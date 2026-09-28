using System;
using System.Collections.Generic;
using System.Linq;
using System.Text.RegularExpressions;
using System.Threading;
using BladeBoost.Core;

namespace BladeBoost.Hardware;

public enum PerformanceMode : byte
{
    Balanced = 0,
    Gaming = 1,
    Creator = 2,
    Custom = 4,
}

public enum BoostLevel : byte
{
    Low = 0,
    Medium = 1,
    High = 2,
    Boost = 3,
}

public sealed record RazerModel(string Name, int FanMin, int FanMax, bool HasCreatorMode, bool HasCpuBoost);

public sealed record RazerState(PerformanceMode Mode, bool ManualFans, int FanRpm);

/// <summary>
/// Controls performance mode and fans on Razer Blade laptops through the same
/// USB HID "feature report" channel Razer Synapse uses.
///
/// Protocol and model list follow the open-source razer-laptop-control project
/// (https://github.com/Razer-Linux/razer-laptop-control-no-dkms), which supports
/// these models on Linux.
/// </summary>
public sealed class RazerLaptop : IDisposable
{
    private const int ReportLength = 91; // 1 report-id byte + 90-byte Razer packet
    private const byte TransactionId = 0x1F;
    private const byte StatusSuccessful = 0x02;
    private const byte StatusBusy = 0x01;
    private const byte StatusNotSupported = 0x05;

    // Command class 0x0D = power & fans.
    private const byte ClassPower = 0x0D;
    private const byte CmdSetFanRpm = 0x01;
    private const byte CmdSetPowerMode = 0x02;
    private const byte CmdSetBoost = 0x07;
    private const byte CmdGetFanRpm = 0x81;
    private const byte CmdGetPowerMode = 0x82;
    private const byte CmdGetBoost = 0x87;

    /// <summary>Razer Blade models (USB product id → details), 2019–2021 generation.</summary>
    public static readonly IReadOnlyDictionary<int, RazerModel> Models = new Dictionary<int, RazerModel>
    {
        [0x0268] = new("Razer Blade 15 Base (Late 2020)", 3600, 5200, false, false),
        [0x0255] = new("Razer Blade 15 Base (2020)", 3500, 5000, false, false),
        [0x0253] = new("Razer Blade 15 Advanced (2020)", 3500, 5300, true, true),
        [0x0246] = new("Razer Blade 15 Base (2019)", 3500, 5000, false, false),
        [0x023A] = new("Razer Blade 15 Advanced (2019)", 3500, 5300, true, false),
        [0x0245] = new("Razer Blade 15 Mercury (2019)", 3500, 5300, true, false),
        [0x026F] = new("Razer Blade 15 Base (2021)", 3500, 5000, false, false),
        [0x027A] = new("Razer Blade 15 Base (Late 2021)", 3500, 5000, false, false),
        [0x0276] = new("Razer Blade 15 Advanced (2021)", 3500, 5000, false, true),
        [0x026D] = new("Razer Blade 15 Advanced (Late 2021)", 3500, 5000, false, true),
        [0x0270] = new("Razer Blade 14 (2021)", 3500, 5000, false, false),
        [0x0252] = new("Razer Blade Stealth 13 (2020)", 3500, 5000, false, false),
        [0x0259] = new("Razer Blade Stealth 13 (Late 2020)", 3500, 5000, false, false),
        [0x0256] = new("Razer Blade Pro 17 (2020)", 3500, 5300, false, false),
        [0x026E] = new("Razer Blade Pro 17 (Early 2021)", 2300, 4300, false, true),
        [0x0279] = new("Razer Blade Pro 17 (Mid 2021)", 2300, 4300, false, true),
    };

    private readonly HidDevice _device;
    private readonly object _gate = new();

    public int ProductId { get; }
    public RazerModel Model { get; }

    private RazerLaptop(HidDevice device, int productId, RazerModel model)
    {
        _device = device;
        ProductId = productId;
        Model = model;
    }

    /// <summary>Finds the laptop's control interface. Returns null with a reason if unavailable.</summary>
    public static RazerLaptop? Find(out string message)
    {
        var razerPids = new SortedSet<int>();
        foreach (var path in HidDevice.EnumeratePaths())
        {
            var match = Regex.Match(path, @"vid_1532&pid_([0-9a-f]{4})", RegexOptions.IgnoreCase);
            if (!match.Success)
            {
                continue;
            }
            var pid = Convert.ToInt32(match.Groups[1].Value, 16);
            razerPids.Add(pid);
            if (!Models.TryGetValue(pid, out var model))
            {
                continue;
            }

            var device = HidDevice.Open(path);
            if (device == null)
            {
                continue;
            }
            if (device.FeatureReportLength != ReportLength)
            {
                device.Dispose();
                continue;
            }

            var laptop = new RazerLaptop(device, pid, model);
            if (laptop.ReadState() != null)
            {
                message = $"{model.Name} connected";
                Log.Write($"Razer control found: {model.Name} (PID {pid:X4}) at {path}");
                return laptop;
            }
            laptop.Dispose();
        }

        message = razerPids.Count == 0
            ? "No Razer laptop controller found"
            : $"Razer device found (PID {string.Join(", ", razerPids.Select(p => p.ToString("X4")))}) but fan control isn't available for it";
        Log.Write(message);
        return null;
    }

    /// <summary>Reads the current performance mode, fan mode and fan speed.</summary>
    public RazerState? ReadState()
    {
        lock (_gate)
        {
            var power = Send(CmdGetPowerMode, 0x00, 0x01, 0x00, 0x00);
            if (power == null)
            {
                return null;
            }
            var mode = Enum.IsDefined(typeof(PerformanceMode), power[2]) ? (PerformanceMode)power[2] : PerformanceMode.Balanced;
            var manual = power[3] == 0x01;
            var fan = Send(CmdGetFanRpm, 0x00, 0x01, 0x00);
            var rpm = fan == null ? 0 : fan[2] * 100;
            return new RazerState(mode, manual, rpm);
        }
    }

    /// <summary>
    /// Applies a performance mode. <paramref name="fanRpm"/> = 0 means automatic fans.
    /// Manual fans aren't available in Custom mode (the laptop manages them there).
    /// </summary>
    public bool Apply(PerformanceMode mode, int fanRpm, BoostLevel cpuBoost, BoostLevel gpuBoost)
    {
        if (mode == PerformanceMode.Creator && !Model.HasCreatorMode)
        {
            mode = PerformanceMode.Gaming;
        }
        if (mode == PerformanceMode.Custom)
        {
            fanRpm = 0;
        }
        if (cpuBoost == BoostLevel.Boost && !Model.HasCpuBoost)
        {
            cpuBoost = BoostLevel.High;
        }
        if (gpuBoost > BoostLevel.High)
        {
            gpuBoost = BoostLevel.High;
        }

        var manual = fanRpm > 0;
        var rpmByte = (byte)(Math.Clamp(fanRpm, Model.FanMin, Model.FanMax) / 100);
        var ok = true;

        lock (_gate)
        {
            foreach (byte zone in new byte[] { 0x01, 0x02 })
            {
                Send(CmdGetPowerMode, 0x00, zone, 0x00, 0x00);
                ok &= Send(CmdSetPowerMode, 0x00, zone, (byte)mode, (byte)(manual ? 0x01 : 0x00)) != null;

                if (mode == PerformanceMode.Custom && zone == 0x01)
                {
                    Send(CmdGetBoost, 0x00, 0x01, 0x00);
                    ok &= Send(CmdSetBoost, 0x00, 0x01, (byte)cpuBoost) != null;
                    Send(CmdGetBoost, 0x00, 0x02, 0x00);
                    ok &= Send(CmdSetBoost, 0x00, 0x02, (byte)gpuBoost) != null;
                }

                if (manual)
                {
                    ok &= Send(CmdSetFanRpm, 0x00, zone, rpmByte) != null;
                }
            }
        }

        Log.Write($"Apply mode={mode} fans={(manual ? rpmByte * 100 + " RPM" : "auto")} cpu={cpuBoost} gpu={gpuBoost} ok={ok}");
        return ok;
    }

    /// <summary>Sends one command and returns the 80 argument bytes of the reply, or null on failure.</summary>
    private byte[]? Send(byte command, params byte[] args)
    {
        var report = BuildReport(ClassPower, command, args);
        for (var attempt = 0; attempt < 3; attempt++)
        {
            if (!_device.SetFeature(report))
            {
                Thread.Sleep(10);
                continue;
            }
            for (var poll = 0; poll < 5; poll++)
            {
                Thread.Sleep(poll == 0 ? 2 : 10);
                var reply = new byte[ReportLength];
                if (!_device.GetFeature(reply))
                {
                    break;
                }
                var status = reply[1];
                if (status == StatusBusy)
                {
                    continue;
                }
                if (status == StatusSuccessful && reply[7] == ClassPower && reply[8] == command)
                {
                    return reply.AsSpan(9, 80).ToArray();
                }
                if (status == StatusNotSupported)
                {
                    Log.Write($"Command 0x0D{command:X2} not supported by this laptop");
                    return null;
                }
                break;
            }
            Thread.Sleep(10);
        }
        return null;
    }

    /// <summary>
    /// Razer packet: [report id][status][transaction id][remaining packets x2][protocol]
    /// [data size][command class][command id][80 argument bytes][crc][reserved].
    /// </summary>
    internal static byte[] BuildReport(byte commandClass, byte command, byte[] args)
    {
        var report = new byte[ReportLength];
        report[2] = TransactionId;
        report[6] = (byte)args.Length;
        report[7] = commandClass;
        report[8] = command;
        Array.Copy(args, 0, report, 9, Math.Min(args.Length, 80));

        // CRC: XOR of packet bytes 2..87 (i.e. after status and transaction id, up to the last argument).
        byte crc = 0;
        for (var i = 3; i <= 88; i++)
        {
            crc ^= report[i];
        }
        report[89] = crc;
        return report;
    }

    public void Dispose() => _device.Dispose();
}
