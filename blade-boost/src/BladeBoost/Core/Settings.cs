using System;
using System.Collections.Generic;
using System.IO;
using System.Text.Json;

namespace BladeBoost.Core;

/// <summary>User choices, saved between runs.</summary>
public sealed class Settings
{
    // Performance & fans
    public string PerformanceMode { get; set; } = "Balanced";
    public string CpuBoost { get; set; } = "High";
    public string GpuBoost { get; set; } = "High";
    public bool ManualFans { get; set; }
    public int FanRpm { get; set; } = 4400;

    // Safety: fans go to maximum above these temperatures (manual fan mode only).
    public int SafetyCpuTemp { get; set; } = 92;
    public int SafetyGpuTemp { get; set; } = 85;

    // Game Mode
    public Dictionary<string, bool> Services { get; set; } = new(StringComparer.OrdinalIgnoreCase);
    public Dictionary<string, bool> Apps { get; set; } = new(StringComparer.OrdinalIgnoreCase);
    public string ExtraApps { get; set; } = "";
    public bool ReopenApps { get; set; } = true;
    public bool BestPerformancePower { get; set; } = true;
    public bool GamingPerformanceMode { get; set; } = true;
    public bool PurgeStandbyMemory { get; set; } = true;
    public bool TrimAppMemory { get; set; }

    public static Settings Load()
    {
        try
        {
            if (File.Exists(AppPaths.Settings))
            {
                var loaded = JsonSerializer.Deserialize<Settings>(File.ReadAllText(AppPaths.Settings), AppPaths.Json);
                if (loaded != null)
                {
                    loaded.Services = new Dictionary<string, bool>(loaded.Services, StringComparer.OrdinalIgnoreCase);
                    loaded.Apps = new Dictionary<string, bool>(loaded.Apps, StringComparer.OrdinalIgnoreCase);
                    return loaded;
                }
            }
        }
        catch (Exception ex)
        {
            Log.Error("loading settings", ex);
        }
        return new Settings();
    }

    public void Save()
    {
        try
        {
            AppPaths.EnsureFolder();
            File.WriteAllText(AppPaths.Settings, JsonSerializer.Serialize(this, AppPaths.Json));
        }
        catch (Exception ex)
        {
            Log.Error("saving settings", ex);
        }
    }
}
