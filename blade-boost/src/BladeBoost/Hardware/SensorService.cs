using System;
using System.Linq;
using BladeBoost.Core;
using LibreHardwareMonitor.Hardware;
using Microsoft.Win32;

namespace BladeBoost.Hardware;

public sealed record SensorReading(
    float? CpuTemp,
    float? CpuLoad,
    float? CpuPower,
    string CpuName,
    float? GpuTemp,
    float? GpuLoad,
    float? GpuClock,
    string GpuName)
{
    public static readonly SensorReading Empty = new(null, null, null, "", null, null, null, "");
}

/// <summary>
/// Reads CPU and GPU sensors with LibreHardwareMonitor. CPU temperatures need
/// the free, Microsoft-signed PawnIO driver; the NVIDIA GPU works without it.
/// Call only from one thread.
/// </summary>
public sealed class SensorService : IDisposable
{
    private Computer? _computer;
    private bool _unavailable;

    public static bool IsPawnIoInstalled()
    {
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\PawnIO");
            return key != null;
        }
        catch
        {
            return false;
        }
    }

    /// <summary>Opens the sensors; if the GPU part fails on this PC, falls back to CPU-only.</summary>
    private Computer? Open()
    {
        if (_computer != null || _unavailable)
        {
            return _computer;
        }
        foreach (var withGpu in new[] { true, false })
        {
            var computer = new Computer { IsCpuEnabled = true, IsGpuEnabled = withGpu };
            try
            {
                computer.Open();
                Log.Write(withGpu ? "Sensors ready" : "Sensors ready (CPU only)");
                return _computer = computer;
            }
            catch (Exception ex)
            {
                Log.Error(withGpu ? "opening sensors (retrying without GPU sensors)" : "opening sensors", ex);
                try
                {
                    computer.Close();
                }
                catch
                {
                    // Ignore: it never opened properly.
                }
            }
        }
        _unavailable = true;
        return null;
    }

    public SensorReading Read()
    {
        var computer = Open();
        if (computer == null)
        {
            return SensorReading.Empty;
        }

        IHardware? cpu = null;
        IHardware? gpu = null;
        IHardware? integratedGpu = null;
        foreach (var hardware in computer.Hardware)
        {
            try
            {
                hardware.Update();
                foreach (var sub in hardware.SubHardware)
                {
                    sub.Update();
                }
            }
            catch
            {
                continue; // one flaky sensor shouldn't hide the others
            }
            switch (hardware.HardwareType)
            {
                case HardwareType.Cpu:
                    cpu ??= hardware;
                    break;
                // Prefer the dedicated NVIDIA/AMD GPU over the integrated Intel one.
                case HardwareType.GpuNvidia or HardwareType.GpuAmd:
                    gpu ??= hardware;
                    break;
                case HardwareType.GpuIntel:
                    integratedGpu ??= hardware;
                    break;
            }
        }
        gpu ??= integratedGpu;

        return new SensorReading(
            CpuTemp: Find(cpu, SensorType.Temperature, "CPU Package", "Core (Tctl/Tdie)", "Core Max", "Core Average"),
            CpuLoad: Find(cpu, SensorType.Load, "CPU Total"),
            CpuPower: Find(cpu, SensorType.Power, "CPU Package", "Package"),
            CpuName: cpu?.Name ?? "",
            GpuTemp: Find(gpu, SensorType.Temperature, "GPU Core", "GPU Hot Spot"),
            GpuLoad: Find(gpu, SensorType.Load, "GPU Core", "D3D 3D"),
            GpuClock: Find(gpu, SensorType.Clock, "GPU Core"),
            GpuName: gpu?.Name ?? "");
    }

    private static float? Find(IHardware? hardware, SensorType type, params string[] names)
    {
        if (hardware == null)
        {
            return null;
        }
        var sensors = hardware.Sensors.Concat(hardware.SubHardware.SelectMany(s => s.Sensors))
            .Where(s => s.SensorType == type && s.Value.HasValue)
            .ToList();
        foreach (var name in names)
        {
            var sensor = sensors.FirstOrDefault(s => string.Equals(s.Name, name, StringComparison.OrdinalIgnoreCase));
            if (sensor?.Value is float value && !float.IsNaN(value) && value > 0)
            {
                return value;
            }
        }
        return null;
    }

    public void Dispose()
    {
        try
        {
            _computer?.Close();
        }
        catch (Exception ex)
        {
            Log.Error("closing sensors", ex);
        }
    }
}
