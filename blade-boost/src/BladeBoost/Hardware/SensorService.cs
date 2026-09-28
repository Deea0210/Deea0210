using System;
using System.Collections.Generic;
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
    string GpuName);

/// <summary>
/// Reads CPU and GPU sensors with LibreHardwareMonitor. CPU temperatures need
/// the free, Microsoft-signed PawnIO driver; the NVIDIA GPU works without it.
/// Call only from one thread.
/// </summary>
public sealed class SensorService : IDisposable
{
    private readonly Computer _computer;
    private bool _opened;

    public SensorService()
    {
        _computer = new Computer { IsCpuEnabled = true, IsGpuEnabled = true };
    }

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

    public SensorReading Read()
    {
        if (!_opened)
        {
            _computer.Open();
            _opened = true;
        }

        IHardware? cpu = null;
        IHardware? gpu = null;
        foreach (var hardware in _computer.Hardware)
        {
            hardware.Update();
            foreach (var sub in hardware.SubHardware)
            {
                sub.Update();
            }
            if (hardware.HardwareType == HardwareType.Cpu && cpu == null)
            {
                cpu = hardware;
            }
            // Prefer the dedicated NVIDIA/AMD GPU over the integrated Intel one.
            if (hardware.HardwareType is HardwareType.GpuNvidia or HardwareType.GpuAmd)
            {
                gpu ??= hardware;
            }
        }
        gpu ??= _computer.Hardware.FirstOrDefault(h => h.HardwareType == HardwareType.GpuIntel);

        return new SensorReading(
            CpuTemp: Find(cpu, SensorType.Temperature, "CPU Package", "Core (Tctl/Tdie)", "Core Max", "Core Average"),
            CpuLoad: Find(cpu, SensorType.Load, "CPU Total"),
            CpuPower: Find(cpu, SensorType.Power, "CPU Package", "Package"),
            CpuName: cpu?.Name ?? "CPU",
            GpuTemp: Find(gpu, SensorType.Temperature, "GPU Core", "GPU Hot Spot"),
            GpuLoad: Find(gpu, SensorType.Load, "GPU Core", "D3D 3D"),
            GpuClock: Find(gpu, SensorType.Clock, "GPU Core"),
            GpuName: gpu?.Name ?? "GPU");
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
            if (_opened)
            {
                _computer.Close();
            }
        }
        catch (Exception ex)
        {
            Log.Error("closing sensors", ex);
        }
    }
}
