using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.ServiceProcess;
using System.Text.Json;
using BladeBoost.Core;
using BladeBoost.Hardware;

namespace BladeBoost.GameMode;

/// <summary>Everything Game Mode changed, saved to disk so Restore works even after a crash or restart.</summary>
public sealed class Snapshot
{
    public DateTime StartedAt { get; set; } = DateTime.Now;
    public List<string> StoppedServices { get; set; } = new();
    public List<ClosedApp> ClosedApps { get; set; } = new();
    public Guid? PreviousPowerMode { get; set; }
    public RazerState? PreviousRazerState { get; set; }

    public static Snapshot? Load()
    {
        try
        {
            return File.Exists(AppPaths.Snapshot)
                ? JsonSerializer.Deserialize<Snapshot>(File.ReadAllText(AppPaths.Snapshot), AppPaths.Json)
                : null;
        }
        catch (Exception ex)
        {
            Log.Error("reading snapshot", ex);
            return null;
        }
    }

    public void Save()
    {
        AppPaths.EnsureFolder();
        File.WriteAllText(AppPaths.Snapshot, JsonSerializer.Serialize(this, AppPaths.Json));
    }

    public static void Delete()
    {
        try
        {
            File.Delete(AppPaths.Snapshot);
        }
        catch (Exception ex)
        {
            Log.Error("deleting snapshot", ex);
        }
    }
}

public sealed record ClosedApp(string Title, string Path);

public sealed record GameModeOptions(
    IReadOnlyList<string> Services,
    IReadOnlyList<AppOption> Apps,
    bool BestPerformancePower,
    bool GamingPerformanceMode,
    bool PurgeStandby,
    bool TrimWorkingSets,
    bool ReopenApps);

/// <summary>Starts and restores Game Mode. Methods block, so call them off the UI thread.</summary>
public sealed class GameModeService
{
    private static readonly TimeSpan ServiceTimeout = TimeSpan.FromSeconds(15);

    public static bool IsActive => File.Exists(AppPaths.Snapshot);

    public List<string> Start(GameModeOptions options, RazerLaptop? laptop, Settings settings)
    {
        var log = new List<string>();
        var snapshot = Snapshot.Load() ?? new Snapshot();
        void Note(string line)
        {
            log.Add(line);
            Log.Write("GameMode: " + line);
        }

        // 1. Windows power mode
        if (options.BestPerformancePower)
        {
            var current = PowerMode.GetCurrent();
            if (current.HasValue && current.Value != PowerMode.BestPerformance)
            {
                snapshot.PreviousPowerMode ??= current;
                Note(PowerMode.Set(PowerMode.BestPerformance)
                    ? "Windows power mode → Best performance"
                    : "Couldn't change the Windows power mode");
            }
        }

        // 2. Razer performance mode
        if (options.GamingPerformanceMode && laptop != null)
        {
            var state = laptop.ReadState();
            if (state != null && state.Mode != PerformanceMode.Gaming)
            {
                snapshot.PreviousRazerState ??= state;
                var fans = settings.ManualFans ? settings.FanRpm : 0;
                Note(laptop.Apply(PerformanceMode.Gaming, fans, BoostLevel.High, BoostLevel.High)
                    ? "Razer performance mode → Gaming"
                    : "Couldn't switch the Razer performance mode");
            }
        }
        snapshot.Save();

        // 3. Services
        foreach (var name in options.Services)
        {
            try
            {
                using var service = new ServiceController(name);
                if (service.Status != ServiceControllerStatus.Running)
                {
                    continue;
                }
                if (!service.CanStop)
                {
                    Note($"{service.DisplayName}: can't be paused (protected by Windows)");
                    continue;
                }
                var runningDependents = service.DependentServices
                    .Where(d => d.Status == ServiceControllerStatus.Running)
                    .Select(d => d.ServiceName)
                    .ToList();

                // Record first, so Restore brings it back even if stopping is slow or the app is closed meanwhile.
                var added = new List<string>();
                foreach (var serviceName in new[] { name }.Concat(runningDependents))
                {
                    if (!snapshot.StoppedServices.Contains(serviceName, StringComparer.OrdinalIgnoreCase))
                    {
                        snapshot.StoppedServices.Add(serviceName);
                        added.Add(serviceName);
                    }
                }
                snapshot.Save();

                try
                {
                    service.Stop(); // also stops services that depend on it
                }
                catch
                {
                    snapshot.StoppedServices.RemoveAll(n => added.Contains(n, StringComparer.OrdinalIgnoreCase));
                    snapshot.Save();
                    throw;
                }
                service.WaitForStatus(ServiceControllerStatus.Stopped, ServiceTimeout);
                Note($"Paused {service.DisplayName}");
            }
            catch (Exception ex)
            {
                Note($"{name}: couldn't pause ({FirstLine(ex)})");
            }
        }

        // 4. Background apps
        foreach (var app in options.Apps)
        {
            var closed = CloseApp(app, out var relaunchPath, out var stillOpen);
            if (closed > 0)
            {
                if (options.ReopenApps && relaunchPath != null
                    && !snapshot.ClosedApps.Any(a => string.Equals(a.Path, relaunchPath, StringComparison.OrdinalIgnoreCase)))
                {
                    snapshot.ClosedApps.Add(new ClosedApp(app.Title, relaunchPath));
                }
                snapshot.Save();
                Note($"Closed {app.Title}");
            }
            if (stillOpen)
            {
                Note($"{app.Title} is still open (it may have unsaved work, close it yourself)");
            }
        }

        // 5. Memory
        if (options.TrimWorkingSets)
        {
            Note(MemoryManager.TrimWorkingSets() ? "Trimmed app memory" : "Couldn't trim app memory");
        }
        if (options.PurgeStandby)
        {
            var before = MemoryManager.Read();
            if (MemoryManager.PurgeStandbyList())
            {
                var after = MemoryManager.Read();
                Note($"Freed {Math.Max(0, after.FreeGb - before.FreeGb):0.0} GB of cached (standby) memory");
            }
            else
            {
                Note("Couldn't free standby memory");
            }
        }

        snapshot.Save();
        Note("Game Mode is on. Press Restore when you're done playing.");
        return log;
    }

    public List<string> Restore(RazerLaptop? laptop, Settings settings)
    {
        var log = new List<string>();
        void Note(string line)
        {
            log.Add(line);
            Log.Write("Restore: " + line);
        }

        var snapshot = Snapshot.Load();
        if (snapshot == null)
        {
            Note("Nothing to restore.");
            return log;
        }

        foreach (var name in snapshot.StoppedServices)
        {
            try
            {
                using var service = new ServiceController(name);
                if (service.Status is ServiceControllerStatus.Stopped or ServiceControllerStatus.StopPending)
                {
                    if (service.Status == ServiceControllerStatus.StopPending)
                    {
                        service.WaitForStatus(ServiceControllerStatus.Stopped, ServiceTimeout);
                    }
                    service.Start();
                    service.WaitForStatus(ServiceControllerStatus.Running, ServiceTimeout);
                }
                Note($"Started {service.DisplayName}");
            }
            catch (Exception ex)
            {
                Note($"{name}: couldn't start ({FirstLine(ex)}). It will start again after a restart.");
            }
        }

        foreach (var app in snapshot.ClosedApps)
        {
            if (LaunchAsUser(app.Path))
            {
                Note($"Reopened {app.Title}");
            }
            else
            {
                Note($"Couldn't reopen {app.Title}, open it from the Start menu");
            }
        }

        if (snapshot.PreviousPowerMode.HasValue)
        {
            Note(PowerMode.Set(snapshot.PreviousPowerMode.Value)
                ? $"Windows power mode → {PowerMode.Describe(snapshot.PreviousPowerMode.Value)}"
                : "Couldn't restore the Windows power mode");
        }

        if (snapshot.PreviousRazerState != null && laptop != null)
        {
            var previous = snapshot.PreviousRazerState;
            var fans = settings.ManualFans ? settings.FanRpm : (previous.ManualFans ? previous.FanRpm : 0);
            Note(laptop.Apply(previous.Mode, fans, ParseBoost(settings.CpuBoost), ParseBoost(settings.GpuBoost))
                ? $"Razer performance mode → {previous.Mode}"
                : "Couldn't restore the Razer performance mode");
        }

        Snapshot.Delete();
        Note("Everything is back to normal.");
        return log;
    }

    /// <summary>Closes an app: windows are asked to close, windowless background processes are ended.</summary>
    private static int CloseApp(AppOption app, out string? relaunchPath, out bool stillOpen)
    {
        relaunchPath = null;
        stillOpen = false;
        var closed = 0;
        var self = Environment.ProcessId;

        foreach (var processName in app.ProcessNames)
        {
            foreach (var process in Process.GetProcessesByName(processName))
            {
                using (process)
                {
                    if (process.Id == self)
                    {
                        continue;
                    }
                    try
                    {
                        if (relaunchPath == null && processName == app.ProcessNames[0])
                        {
                            relaunchPath = TryGetPath(process);
                        }
                        if (process.MainWindowHandle != IntPtr.Zero)
                        {
                            process.CloseMainWindow();
                            if (!process.WaitForExit(4000))
                            {
                                // Apps like Discord hide in the tray instead of exiting; those are safe to end.
                                process.Refresh();
                                if (process.MainWindowHandle != IntPtr.Zero)
                                {
                                    stillOpen = true;
                                    continue;
                                }
                                process.Kill();
                                process.WaitForExit(3000);
                            }
                        }
                        else
                        {
                            process.Kill();
                            process.WaitForExit(3000);
                        }
                        closed++;
                    }
                    catch (Exception ex)
                    {
                        Log.Write($"Closing {processName} ({process.Id}): {FirstLine(ex)}");
                    }
                }
            }
        }
        return closed;
    }

    private static string? TryGetPath(Process process)
    {
        try
        {
            return process.MainModule?.FileName;
        }
        catch
        {
            return null;
        }
    }

    /// <summary>
    /// Starts a program as the normal (non-administrator) user by asking Explorer
    /// to open it, so reopened apps don't inherit this app's admin rights.
    /// </summary>
    private static bool LaunchAsUser(string path)
    {
        try
        {
            if (!File.Exists(path))
            {
                return false;
            }
            Process.Start(new ProcessStartInfo("explorer.exe", $"\"{path}\"") { UseShellExecute = false });
            return true;
        }
        catch (Exception ex)
        {
            Log.Error($"reopening {path}", ex);
            return false;
        }
    }

    public static BoostLevel ParseBoost(string value) =>
        Enum.TryParse<BoostLevel>(value, true, out var level) ? level : BoostLevel.High;

    private static string FirstLine(Exception ex)
    {
        var inner = ex.InnerException?.Message ?? ex.Message;
        var line = inner.Split('\n')[0].Trim();
        return line.Length > 120 ? line[..120] + "…" : line;
    }
}
