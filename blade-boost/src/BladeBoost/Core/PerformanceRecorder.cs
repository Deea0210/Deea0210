using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Text;
using BladeBoost.Hardware;

namespace BladeBoost.Core;

/// <summary>One second of measurements taken during a Game Mode session.</summary>
public sealed record PerfSample(
    double Seconds,
    float? CpuTemp,
    float? CpuLoad,
    float? CpuPower,
    float? GpuTemp,
    float? GpuLoad,
    float? GpuClock,
    int FanRpm);

/// <summary>Frame rate for one second of a session, counted from PresentMon's frame times.</summary>
internal sealed record FpsSecond(double Seconds, double Fps);

/// <summary>
/// Records temperatures, load, clocks, power, fan speed and frame rate for as long as
/// Game Mode is on, then writes a CSV and a self-contained HTML report with graphs.
///
/// Frame rate comes from PresentMon, which arrives with NVIDIA's driver package. If it is
/// not there the report simply leaves frame rate out and everything else still works.
/// Call Add from one thread only, the same way SensorService is used.
/// </summary>
public sealed class PerformanceRecorder
{
    /// <summary>Where NVIDIA's installer leaves PresentMon, relative to Program Files.</summary>
    private static readonly string[] PresentMonPaths =
    {
        @"NVIDIA Corporation\FrameViewSDK\bin\PresentMon_x64.exe",
        @"NVIDIA Corporation\FrameView\PresentMon_x64.exe",
    };

    private readonly List<PerfSample> _samples = new();
    private readonly Stopwatch _clock = new();
    private Process? _presentMon;
    private string? _fpsCsv;
    private double _fpsOffset;
    private DateTime _startedAt;
    private string _cpuName = "";
    private string _gpuName = "";

    public bool IsRecording { get; private set; }

    /// <summary>The last report written, so the window can offer to open it again.</summary>
    public string? LastReportPath { get; private set; }

    /// <summary>Begins a session. Safe to call when one is already running: it starts over.</summary>
    public void Start()
    {
        Stop(writeReport: false);
        _samples.Clear();
        _cpuName = "";
        _gpuName = "";
        _startedAt = DateTime.Now;
        _clock.Restart();
        IsRecording = true;
        StartPresentMon();
        Log.Write("Recording: performance log started");
    }

    /// <summary>Adds the reading the sensor loop has just taken.</summary>
    public void Add(SensorReading reading, RazerState? state)
    {
        if (!IsRecording)
        {
            return;
        }
        if (_cpuName.Length == 0)
        {
            _cpuName = reading.CpuName;
        }
        if (_gpuName.Length == 0)
        {
            _gpuName = reading.GpuName;
        }
        _samples.Add(new PerfSample(
            Seconds: _clock.Elapsed.TotalSeconds,
            CpuTemp: reading.CpuTemp,
            CpuLoad: reading.CpuLoad,
            CpuPower: reading.CpuPower,
            GpuTemp: reading.GpuTemp,
            GpuLoad: reading.GpuLoad,
            GpuClock: reading.GpuClock,
            FanRpm: state?.FanRpm ?? 0));
    }

    /// <summary>
    /// Ends the session and writes the report. Returns the HTML file, or null if the session
    /// was too short to say anything or writing it failed.
    /// </summary>
    public string? Stop(bool writeReport = true)
    {
        if (!IsRecording)
        {
            return null;
        }
        IsRecording = false;
        _clock.Stop();
        var frames = StopPresentMon();

        if (!writeReport)
        {
            return null;
        }
        if (_samples.Count < 10)
        {
            Log.Write($"Recording: only {_samples.Count} seconds, too short for a report");
            return null;
        }
        try
        {
            Directory.CreateDirectory(AppPaths.Reports);
            var stamp = _startedAt.ToString("yyyyMMdd-HHmmss", CultureInfo.InvariantCulture);
            var csv = Path.Combine(AppPaths.Reports, $"session-{stamp}.csv");
            var html = Path.Combine(AppPaths.Reports, $"session-{stamp}.html");
            File.WriteAllText(csv, BuildCsv(frames), Encoding.UTF8);
            File.WriteAllText(html, BuildHtml(frames, Path.GetFileName(csv)), Encoding.UTF8);
            LastReportPath = html;
            Log.Write($"Recording: report written, {_samples.Count} seconds, {Path.GetFileName(html)}");
            return html;
        }
        catch (Exception ex)
        {
            Log.Error("writing the performance report", ex);
            return null;
        }
    }

    // ---------- Frame rate ----------

    private static string? FindPresentMon()
    {
        var roots = new[] { Environment.SpecialFolder.ProgramFiles, Environment.SpecialFolder.ProgramFilesX86 };
        foreach (var root in roots)
        {
            var baseDir = Environment.GetFolderPath(root);
            if (string.IsNullOrEmpty(baseDir))
            {
                continue;
            }
            foreach (var relative in PresentMonPaths)
            {
                var path = Path.Combine(baseDir, relative);
                if (File.Exists(path))
                {
                    return path;
                }
            }
        }
        return null;
    }

    private void StartPresentMon()
    {
        _fpsCsv = null;
        var exe = FindPresentMon();
        if (exe == null)
        {
            Log.Write("Recording: PresentMon not installed, so the report will have no frame rate");
            return;
        }
        try
        {
            Directory.CreateDirectory(AppPaths.Reports);
            var csv = Path.Combine(AppPaths.Reports, "frames.csv");
            var info = new ProcessStartInfo(exe)
            {
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
            };
            // v1 metrics keep the long-standing, simple column names this parser reads.
            var args = new[]
            {
                "--v1_metrics", "--no_console_stats", "--stop_existing_session",
                "--session_name", "BladeBoostPerf", "--output_file", csv,
            };
            foreach (var arg in args)
            {
                info.ArgumentList.Add(arg);
            }
            _presentMon = Process.Start(info);
            _fpsCsv = csv;
            // Frame times are measured from PresentMon's own start, which is a moment after
            // ours. Remember the gap so frames land on the right second of this session.
            _fpsOffset = _clock.Elapsed.TotalSeconds;
            Log.Write("Recording: frame rate capture started");
        }
        catch (Exception ex)
        {
            // Not fatal: the temperature side of the report is the useful half anyway.
            Log.Error("starting the frame rate capture", ex);
            _presentMon = null;
            _fpsCsv = null;
        }
    }

    /// <summary>Stops the capture and reads its CSV. Empty list if there is nothing usable.</summary>
    private List<FpsSecond> StopPresentMon()
    {
        var process = _presentMon;
        _presentMon = null;
        if (process != null)
        {
            try
            {
                if (!process.HasExited)
                {
                    // PresentMon writes rows as it goes, so stopping it this way loses at
                    // most the last part-second.
                    process.Kill();
                    process.WaitForExit(4000);
                }
            }
            catch (Exception ex)
            {
                Log.Error("stopping the frame rate capture", ex);
            }
            finally
            {
                process.Dispose();
            }
        }
        var csv = _fpsCsv;
        _fpsCsv = null;
        return csv == null ? new List<FpsSecond>() : ReadFrames(csv, _fpsOffset);
    }

    /// <summary>
    /// Turns PresentMon's one-row-per-frame CSV into a frame rate for each second. It records
    /// every program that draws, so the one with the most frames is taken to be the game.
    /// </summary>
    private static List<FpsSecond> ReadFrames(string csv, double offset)
    {
        var result = new List<FpsSecond>();
        try
        {
            if (!File.Exists(csv))
            {
                return result;
            }
            var lines = File.ReadAllLines(csv);
            if (lines.Length < 3)
            {
                return result;
            }
            var header = lines[0].Split(',');
            var appColumn = ColumnOf(header, "Application");
            var timeColumn = ColumnOf(header, "TimeInSeconds");
            if (timeColumn < 0)
            {
                return result;
            }

            // Count frames per program so the busiest one can be picked out.
            var counts = new Dictionary<string, int>(StringComparer.OrdinalIgnoreCase);
            var rows = new List<(string App, double Time)>();
            for (var i = 1; i < lines.Length; i++)
            {
                var cells = lines[i].Split(',');
                if (cells.Length <= timeColumn || !double.TryParse(
                        cells[timeColumn], NumberStyles.Float, CultureInfo.InvariantCulture, out var seconds))
                {
                    continue;
                }
                var name = appColumn >= 0 && cells.Length > appColumn ? cells[appColumn].Trim('"') : "";
                counts[name] = counts.GetValueOrDefault(name) + 1;
                rows.Add((name, seconds));
            }
            if (rows.Count == 0)
            {
                return result;
            }
            var game = counts.OrderByDescending(pair => pair.Value).First().Key;

            // One bucket per whole second of the session; the frames in it ARE the frame rate.
            var buckets = new SortedDictionary<long, int>();
            foreach (var row in rows)
            {
                if (!string.Equals(row.App, game, StringComparison.OrdinalIgnoreCase))
                {
                    continue;
                }
                var second = (long)Math.Floor(row.Time + offset);
                buckets[second] = buckets.GetValueOrDefault(second) + 1;
            }
            // The game's first and last seconds are part-seconds, so they read low. Drop them.
            foreach (var bucket in buckets.Skip(1).Take(Math.Max(0, buckets.Count - 2)))
            {
                result.Add(new FpsSecond(bucket.Key, bucket.Value));
            }
        }
        catch (Exception ex)
        {
            Log.Error("reading the frame rate capture", ex);
        }
        return result;

        static int ColumnOf(string[] header, string name)
        {
            for (var i = 0; i < header.Length; i++)
            {
                if (string.Equals(header[i].Trim().Trim('"'), name, StringComparison.OrdinalIgnoreCase))
                {
                    return i;
                }
            }
            return -1;
        }
    }

    // ---------- Output ----------

    private string BuildCsv(List<FpsSecond> frames)
    {
        var fps = frames.ToDictionary(f => (long)f.Seconds, f => f.Fps);
        var text = new StringBuilder();
        text.AppendLine("second,cpu_temp_c,cpu_load_pct,cpu_power_w,gpu_temp_c,gpu_load_pct,gpu_clock_mhz,fan_rpm,fps");
        foreach (var sample in _samples)
        {
            var second = (long)Math.Floor(sample.Seconds);
            text.Append(second.ToString(CultureInfo.InvariantCulture)).Append(',')
                .Append(Number(sample.CpuTemp)).Append(',')
                .Append(Number(sample.CpuLoad)).Append(',')
                .Append(Number(sample.CpuPower)).Append(',')
                .Append(Number(sample.GpuTemp)).Append(',')
                .Append(Number(sample.GpuLoad)).Append(',')
                .Append(Number(sample.GpuClock)).Append(',')
                .Append(sample.FanRpm.ToString(CultureInfo.InvariantCulture)).Append(',')
                .AppendLine(fps.TryGetValue(second, out var rate)
                    ? rate.ToString("0", CultureInfo.InvariantCulture)
                    : "");
        }
        return text.ToString();

        static string Number(float? value) =>
            value.HasValue ? value.Value.ToString("0.#", CultureInfo.InvariantCulture) : "";
    }

    private const string ReportStyle = """
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>BladeBoost Session</title>
        <style>
          :root { --bg:#0E1014; --card:#161A21; --card2:#1D222B; --line:#2A303C; --text:#EDEFF4;
                  --muted:#8C95A8; --accent:#45D483; --warn:#F5B83D; --danger:#FF5D5D; --blue:#6AA9FF; }
          * { box-sizing:border-box; }
          body { margin:0; padding:30px 18px 60px; background:var(--bg); color:var(--text);
                 font:15px/1.55 "Segoe UI Variable Text","Segoe UI",system-ui,sans-serif; }
          .wrap { max-width:980px; margin:0 auto; }
          h1 { font-size:27px; margin:0 0 5px; letter-spacing:-0.015em; font-weight:600; }
          .sub { color:var(--muted); margin:0 0 8px; }
          .kit { color:var(--muted); font-size:13px; margin:0 0 26px; }
          .card { background:var(--card); border:1px solid var(--line); border-radius:12px;
                  padding:18px 20px; margin:0 0 18px; }
          .card h2 { font-size:12px; text-transform:uppercase; letter-spacing:0.09em;
                     color:var(--muted); margin:0 0 15px; font-weight:600; }
          .tiles { display:grid; grid-template-columns:repeat(auto-fit,minmax(132px,1fr)); gap:12px; }
          .tile { background:var(--card2); border:1px solid var(--line); border-radius:10px; padding:12px 14px; }
          .tile .k { color:var(--muted); font-size:11.5px; text-transform:uppercase; letter-spacing:0.06em; }
          .tile .v { font-size:24px; font-weight:600; font-variant-numeric:tabular-nums; margin-top:3px;
                     letter-spacing:-0.01em; }
          .legend { display:flex; flex-wrap:wrap; gap:18px; margin:-4px 0 12px; font-size:13px; color:var(--muted); }
          .legend i { display:inline-block; width:12px; height:3px; border-radius:2px;
                      vertical-align:middle; margin-right:7px; }
          svg { width:100%; height:auto; display:block; }
          .note { color:var(--muted); font-size:13.5px; margin:0; }
          .note b { color:var(--text); font-weight:600; }
          @media (max-width:560px) { body { padding:20px 12px 44px; } h1 { font-size:21px; } }
        </style></head><body><div class="wrap">
        """;

    private string BuildHtml(List<FpsSecond> frames, string csvName)
    {
        var duration = _samples[^1].Seconds;
        var cpuPeak = Peak(s => s.CpuTemp);
        var gpuPeak = Peak(s => s.GpuTemp);
        var fanPeak = _samples.Max(s => s.FanRpm);
        var html = new StringBuilder(ReportStyle);

        html.Append("<h1>Game Mode session</h1><p class=\"sub\">")
            .Append(Escape(_startedAt.ToString("dddd d MMMM yyyy, HH:mm")))
            .Append(" &middot; lasted ").Append(Escape(Duration(duration)))
            .Append("</p>");
        if (_cpuName.Length > 0 || _gpuName.Length > 0)
        {
            html.Append("<p class=\"kit\">").Append(Escape(string.Join("  +  ",
                new[] { _cpuName, _gpuName }.Where(n => n.Length > 0)))).Append("</p>");
        }

        html.Append("<div class=\"card\"><h2>Summary</h2><div class=\"tiles\">");
        Tile("CPU peak", cpuPeak.HasValue ? $"{cpuPeak:0}&deg;C" : "n/a", Heat(cpuPeak, 80, 92));
        Tile("CPU average", Avg(s => s.CpuTemp) is double cpuAvg ? $"{cpuAvg:0}&deg;C" : "n/a", null);
        Tile("GPU peak", gpuPeak.HasValue ? $"{gpuPeak:0}&deg;C" : "n/a", Heat(gpuPeak, 75, 85));
        Tile("GPU average", Avg(s => s.GpuTemp) is double gpuAvg ? $"{gpuAvg:0}&deg;C" : "n/a", null);
        Tile("Fan peak", fanPeak > 0 ? $"{fanPeak:N0} RPM" : "auto", null);
        if (frames.Count > 0)
        {
            var sorted = frames.Select(f => f.Fps).OrderBy(v => v).ToList();
            Tile("Average FPS", $"{frames.Average(f => f.Fps):0}", "var(--accent)");
            Tile("1% low FPS", $"{sorted[(int)(sorted.Count * 0.01)]:0}", "var(--warn)");
        }
        html.Append("</div></div>");

        Chart("Temperature", "&deg;C", 0, 100,
            ("CPU", "var(--danger)", Series(s => s.CpuTemp)),
            ("GPU", "var(--blue)", Series(s => s.GpuTemp)));

        Chart("How hard they were working", "%", 0, 100,
            ("CPU load", "var(--accent)", Series(s => s.CpuLoad)),
            ("GPU load", "var(--blue)", Series(s => s.GpuLoad)));

        Chart("GPU clock", "MHz", 0, null, ("GPU clock", "var(--blue)", Series(s => s.GpuClock)));
        Chart("CPU package power", "W", 0, null, ("CPU power", "var(--warn)", Series(s => s.CpuPower)));
        if (fanPeak > 0)
        {
            Chart("Fan speed", "RPM", 0, null, ("Fans", "var(--muted)", Series(s => (float?)s.FanRpm)));
        }
        if (frames.Count > 0)
        {
            var bySecond = frames.ToDictionary(f => (long)f.Seconds, f => f.Fps);
            var fps = _samples
                .Select(s => bySecond.TryGetValue((long)Math.Floor(s.Seconds), out var v) ? v : (double?)null)
                .ToArray();
            Chart("Frame rate", "FPS", 0, null, ("FPS", "var(--accent)", fps));
        }

        html.Append("<div class=\"card\"><h2>Notes</h2><p class=\"note\">");
        if (!cpuPeak.HasValue)
        {
            html.Append("<b>No CPU temperature was recorded.</b> That needs the PawnIO driver. ")
                .Append("Install it from BladeBoost's main window, then run a session again.<br><br>");
        }
        if (frames.Count == 0)
        {
            html.Append("<b>No frame rate was recorded.</b> That comes from PresentMon, which arrives ")
                .Append("with NVIDIA's driver package, and it only counts frames while a game is ")
                .Append("actually drawing. Nothing else in this report is affected.<br><br>");
        }
        if (fanPeak == 0)
        {
            html.Append("<b>Fan speed reads as automatic.</b> The laptop was choosing the fan speed ")
                .Append("itself, so there was no set speed to record.<br><br>");
        }
        html.Append("Every reading behind these graphs is in <b>").Append(Escape(csvName))
            .Append("</b>, saved beside this file, if you want it in a spreadsheet.<br><br>")
            .Append("To compare two sessions fairly, play the same game for about the same length of ")
            .Append("time, with the laptop in the same place and the room at a similar temperature.")
            .Append("</p></div>");

        html.Append("</div></body></html>");
        return html.ToString();

        // ----- helpers, kept local so they can see the samples and the builder -----

        double? Peak(Func<PerfSample, float?> pick)
        {
            var values = _samples.Select(pick).Where(v => v.HasValue).Select(v => (double)v!.Value).ToList();
            return values.Count == 0 ? null : values.Max();
        }

        double? Avg(Func<PerfSample, float?> pick)
        {
            var values = _samples.Select(pick).Where(v => v.HasValue).Select(v => (double)v!.Value).ToList();
            return values.Count == 0 ? null : values.Average();
        }

        double?[] Series(Func<PerfSample, float?> pick) =>
            _samples.Select(s => pick(s) is float v ? v : (double?)null).ToArray();

        string? Heat(double? value, double warm, double hot) => !value.HasValue
            ? null
            : value >= hot ? "var(--danger)" : value >= warm ? "var(--warn)" : "var(--accent)";

        void Tile(string key, string value, string? colour)
        {
            html.Append("<div class=\"tile\"><div class=\"k\">").Append(Escape(key))
                .Append("</div><div class=\"v\"");
            if (colour != null)
            {
                html.Append(" style=\"color:").Append(colour).Append('"');
            }
            html.Append('>').Append(value).Append("</div></div>");
        }

        void Chart(string title, string unit, double yMin, double? yMax,
            params (string Label, string Colour, double?[] Values)[] series)
        {
            // A series that was never readable is left out rather than drawn as a flat line.
            var drawn = series.Where(s => s.Values.Any(v => v.HasValue)).ToArray();
            if (drawn.Length == 0)
            {
                return;
            }
            var top = yMax ?? NiceMax(drawn
                .SelectMany(s => s.Values).Where(v => v.HasValue).Select(v => v!.Value)
                .DefaultIfEmpty(1).Max());

            html.Append("<div class=\"card\"><h2>").Append(Escape(title)).Append("</h2>");
            html.Append("<div class=\"legend\">");
            foreach (var s in drawn)
            {
                html.Append("<span><i style=\"background:").Append(s.Colour).Append("\"></i>")
                    .Append(Escape(s.Label));
                if (!s.Label.EndsWith(unit, StringComparison.OrdinalIgnoreCase))
                {
                    html.Append(' ').Append(unit);
                }
                html.Append("</span>");
            }
            html.Append("</div>").Append(Svg(drawn, yMin, top, duration)).Append("</div>");
        }
    }

    /// <summary>Draws one chart as inline SVG, so the report needs nothing from the internet.</summary>
    private static string Svg((string Label, string Colour, double?[] Values)[] series,
        double yMin, double yMax, double duration)
    {
        const int width = 920, height = 230, padLeft = 54, padRight = 14, padTop = 12, padBottom = 26;
        var plotWidth = width - padLeft - padRight;
        var plotHeight = height - padTop - padBottom;
        var count = series[0].Values.Length;
        // Keep the file small and the line clean: never more than one point per pixel across.
        var step = Math.Max(1, count / plotWidth);

        var svg = new StringBuilder();
        svg.Append("<svg viewBox=\"0 0 ").Append(width).Append(' ').Append(height)
           .Append("\" xmlns=\"http://www.w3.org/2000/svg\" role=\"img\">");

        // Grid lines, with the scale down the left.
        for (var i = 0; i <= 4; i++)
        {
            var y = padTop + plotHeight * i / 4.0;
            var value = yMax - (yMax - yMin) * i / 4.0;
            svg.Append("<line x1=\"").Append(padLeft).Append("\" y1=\"").Append(Round(y))
               .Append("\" x2=\"").Append(width - padRight).Append("\" y2=\"").Append(Round(y))
               .Append("\" stroke=\"#2A303C\" stroke-width=\"1\"/>")
               .Append("<text x=\"").Append(padLeft - 10).Append("\" y=\"").Append(Round(y + 4))
               .Append("\" fill=\"#8C95A8\" font-size=\"11\" text-anchor=\"end\">")
               .Append(value.ToString("0", CultureInfo.InvariantCulture)).Append("</text>");
        }

        // Elapsed time along the bottom.
        for (var i = 0; i <= 6; i++)
        {
            var x = padLeft + plotWidth * i / 6.0;
            var anchor = i == 0 ? "start" : i == 6 ? "end" : "middle";
            svg.Append("<text x=\"").Append(Round(x)).Append("\" y=\"").Append(height - 7)
               .Append("\" fill=\"#8C95A8\" font-size=\"11\" text-anchor=\"").Append(anchor).Append("\">")
               .Append(Escape(Duration(duration * i / 6.0))).Append("</text>");
        }

        foreach (var line in series)
        {
            var path = new StringBuilder();
            var drawing = false;
            for (var i = 0; i < count; i += step)
            {
                if (line.Values[i] is not double value)
                {
                    drawing = false; // a gap in the readings should be a gap in the line
                    continue;
                }
                var x = padLeft + (count <= 1 ? 0 : plotWidth * (double)i / (count - 1));
                var fraction = Math.Clamp((value - yMin) / Math.Max(0.0001, yMax - yMin), 0, 1);
                var y = padTop + plotHeight * (1 - fraction);
                path.Append(drawing ? 'L' : 'M').Append(Round(x)).Append(' ').Append(Round(y)).Append(' ');
                drawing = true;
            }
            if (path.Length > 0)
            {
                svg.Append("<path d=\"").Append(path).Append("\" fill=\"none\" stroke=\"")
                   .Append(line.Colour)
                   .Append("\" stroke-width=\"1.8\" stroke-linejoin=\"round\" stroke-linecap=\"round\"/>");
            }
        }
        svg.Append("</svg>");
        return svg.ToString();

        static string Round(double value) => value.ToString("0.#", CultureInfo.InvariantCulture);
    }

    /// <summary>Rounds a scale up to the next readable number, so axes read 2000 not 2046.</summary>
    private static double NiceMax(double peak)
    {
        if (peak <= 0)
        {
            return 1;
        }
        peak *= 1.01; // a hair of headroom so the highest point is not on the top line
        var magnitude = Math.Pow(10, Math.Floor(Math.Log10(peak)));
        foreach (var step in new[] { 1.0, 1.2, 1.5, 2.0, 2.5, 3.0, 4.0, 5.0, 6.0, 8.0 })
        {
            if (peak <= step * magnitude)
            {
                return step * magnitude;
            }
        }
        return 10 * magnitude;
    }

    private static string Duration(double seconds) => seconds >= 3600
        ? $"{(int)(seconds / 3600)}h {(int)(seconds % 3600 / 60):00}m"
        : seconds >= 60
            ? $"{(int)(seconds / 60)}m {(int)(seconds % 60):00}s"
            : $"{seconds:0}s";

    private static string Escape(string text) => text
        .Replace("&", "&amp;").Replace("<", "&lt;").Replace(">", "&gt;").Replace("\"", "&quot;");
}
