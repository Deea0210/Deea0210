using System;
using BladeBoost.Core;

namespace BladeBoost.Hardware;

/// <summary>The Windows "Power mode" setting (Settings → System → Power &amp; battery).</summary>
internal static class PowerMode
{
    public static readonly Guid BestPerformance = new("ded574b5-45a0-4f42-8737-46345c09c238");
    public static readonly Guid Balanced = Guid.Empty;
    public static readonly Guid BestPowerEfficiency = new("961cc777-2547-4f9d-8174-7d86181b8a7a");

    public static Guid? GetCurrent()
    {
        try
        {
            return NativeMethods.PowerGetEffectiveOverlayScheme(out var overlay) == 0 ? overlay : null;
        }
        catch (Exception ex) when (ex is EntryPointNotFoundException or DllNotFoundException)
        {
            return null;
        }
    }

    public static bool Set(Guid overlay)
    {
        try
        {
            var ok = NativeMethods.PowerSetActiveOverlayScheme(ref overlay) == 0;
            Log.Write($"Power mode → {Describe(overlay)}: {(ok ? "ok" : "failed")}");
            return ok;
        }
        catch (Exception ex) when (ex is EntryPointNotFoundException or DllNotFoundException)
        {
            return false;
        }
    }

    public static string Describe(Guid overlay) =>
        overlay == BestPerformance ? "Best performance"
        : overlay == BestPowerEfficiency ? "Best power efficiency"
        : overlay == Balanced ? "Balanced"
        : overlay.ToString();
}
