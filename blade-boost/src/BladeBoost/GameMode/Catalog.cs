using System;
using System.Collections.Generic;
using System.Linq;
using System.ServiceProcess;
using BladeBoost.Core;

namespace BladeBoost.GameMode;

public sealed record ServiceOption(string Name, string Title, string Why, bool DefaultOn);

public sealed record AppOption(string Key, string Title, string[] ProcessNames, bool DefaultOn);

/// <summary>
/// What Game Mode may pause. Only services that are safe to stop for a gaming
/// session are listed: nothing for security, networking, audio, display or drivers.
/// Every stopped service is started again by Restore (and by a Windows restart).
/// </summary>
public static class Catalog
{
    public static readonly ServiceOption[] Services =
    {
        new("SysMain", "SysMain (Superfetch)", "Preloads apps into RAM and uses disk in the background", true),
        new("WSearch", "Windows Search indexing", "Indexes files in the background; search still works, just slower", true),
        new("DiagTrack", "Telemetry (Connected User Experiences)", "Sends diagnostic data to Microsoft", true),
        new("dmwappushservice", "Device management push", "Telemetry message routing", true),
        new("WerSvc", "Windows Error Reporting", "Collects crash reports", true),
        new("PcaSvc", "Program Compatibility Assistant", "Watches programs for compatibility problems", true),
        new("Spooler", "Print Spooler", "Only needed when printing", true),
        new("Fax", "Fax", "Only needed for faxing", true),
        new("MapsBroker", "Downloaded Maps Manager", "Updates offline maps", true),
        new("wisvc", "Windows Insider Service", "Only needed for Insider builds", true),
        new("RetailDemo", "Retail Demo", "Store demo mode", true),
        new("edgeupdate", "Microsoft Edge Update", "Checks for Edge updates", true),
        new("edgeupdatem", "Microsoft Edge Update (on demand)", "Checks for Edge updates", true),
        new("gupdate", "Google Update", "Checks for Chrome updates", true),
        new("gupdatem", "Google Update (on demand)", "Checks for Chrome updates", true),
        new("AdobeARMservice", "Adobe Acrobat Update", "Checks for Acrobat updates", true),
        new("AGSService", "Adobe Genuine Software", "Adobe licence checks", true),
        new("AGMService", "Adobe Genuine Monitor", "Adobe licence checks", true),
        new("wuauserv", "Windows Update", "Stops downloads during play (may restart on its own)", false),
        new("BITS", "Background downloads (BITS)", "Used by Windows Update and some apps", false),
        new("XblAuthManager", "Xbox Live Auth Manager", "Keep ON if you play Xbox / Game Pass games", false),
        new("XblGameSave", "Xbox Live Game Save", "Keep ON if you play Xbox / Game Pass games", false),
        new("XboxNetApiSvc", "Xbox Live Networking", "Keep ON if you play Xbox / Game Pass games", false),
    };

    public static readonly AppOption[] Apps =
    {
        new("razer", "Razer Synapse & Razer Central", new[] { "Razer Synapse 3", "Razer Synapse", "RazerCentral", "Razer Synapse Service Process", "RazerAppEngine", "RzChromaSDKServer", "RzChromaStreamServer", "RazerCortex" }, true),
        new("onedrive", "OneDrive", new[] { "OneDrive" }, true),
        new("teams", "Microsoft Teams", new[] { "ms-teams", "Teams" }, true),
        new("phonelink", "Phone Link", new[] { "PhoneExperienceHost" }, true),
        new("widgets", "Widgets", new[] { "Widgets", "WidgetService" }, true),
        new("adobecc", "Adobe Creative Cloud", new[] { "Creative Cloud", "Adobe Desktop Service", "CCXProcess", "CCLibrary", "CoreSync", "AdobeIPCBroker", "AdobeUpdateService" }, true),
        new("dropbox", "Dropbox", new[] { "Dropbox" }, true),
        new("gdrive", "Google Drive", new[] { "GoogleDriveFS" }, true),
        new("skype", "Skype", new[] { "Skype" }, true),
        new("discord", "Discord", new[] { "Discord" }, false),
        new("spotify", "Spotify", new[] { "Spotify" }, false),
        new("chrome", "Google Chrome", new[] { "chrome" }, false),
        new("edge", "Microsoft Edge", new[] { "msedge" }, false),
        new("firefox", "Firefox", new[] { "firefox" }, false),
    };

    /// <summary>Catalog services that exist on this PC, plus any leftover Razer services.</summary>
    public static List<ServiceOption> InstalledServices()
    {
        var result = new List<ServiceOption>();
        try
        {
            var installed = ServiceController.GetServices();
            var names = new HashSet<string>(installed.Select(s => s.ServiceName), StringComparer.OrdinalIgnoreCase);
            result.AddRange(Services.Where(s => names.Contains(s.Name)));

            foreach (var service in installed.OrderBy(s => s.DisplayName))
            {
                if (service.DisplayName.StartsWith("Razer", StringComparison.OrdinalIgnoreCase)
                    || service.ServiceName.StartsWith("Razer", StringComparison.OrdinalIgnoreCase)
                    || service.ServiceName.StartsWith("Rz", StringComparison.OrdinalIgnoreCase))
                {
                    result.Insert(0, new ServiceOption(service.ServiceName, service.DisplayName, "Razer Synapse background service", true));
                }
            }
            foreach (var service in installed)
            {
                service.Dispose();
            }
        }
        catch (Exception ex)
        {
            Log.Error("listing services", ex);
            result.AddRange(Services);
        }
        return result;
    }

    /// <summary>Extra process names typed by the user, e.g. "Slack, obs64".</summary>
    public static IEnumerable<AppOption> ParseExtraApps(string text) =>
        text.Split(new[] { ',', ';', '\n' }, StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .Select(n => n.EndsWith(".exe", StringComparison.OrdinalIgnoreCase) ? n[..^4] : n)
            .Where(n => n.Length > 0)
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .Select(n => new AppOption("extra:" + n, n, new[] { n }, true));
}
