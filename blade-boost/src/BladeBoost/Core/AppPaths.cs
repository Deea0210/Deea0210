using System;
using System.IO;
using System.Text.Json;

namespace BladeBoost.Core;

/// <summary>Where settings, the Game Mode snapshot and the log live.</summary>
internal static class AppPaths
{
    public static readonly string Folder = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BladeBoost");

    public static string Settings => Path.Combine(Folder, "settings.json");
    public static string Snapshot => Path.Combine(Folder, "gamemode-snapshot.json");
    public static string LogFile => Path.Combine(Folder, "log.txt");

    public static readonly JsonSerializerOptions Json = new() { WriteIndented = true };

    public static void EnsureFolder() => Directory.CreateDirectory(Folder);
}

/// <summary>Tiny append-only log so problems can be diagnosed after the fact.</summary>
internal static class Log
{
    private static readonly object Gate = new();

    public static void Write(string message)
    {
        try
        {
            lock (Gate)
            {
                AppPaths.EnsureFolder();
                var file = new FileInfo(AppPaths.LogFile);
                if (file.Exists && file.Length > 1_000_000)
                {
                    file.Delete();
                }
                File.AppendAllText(AppPaths.LogFile, $"{DateTime.Now:yyyy-MM-dd HH:mm:ss}  {message}{Environment.NewLine}");
            }
        }
        catch
        {
            // Logging must never break the app.
        }
    }

    public static void Error(string context, Exception ex) => Write($"ERROR {context}: {ex}");
}
