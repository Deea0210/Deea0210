using System;

namespace BladeBoost.Core;

/// <summary>
/// A temperature-driven fan curve.
///
/// The Razer fan interface is binary: 0 hands the fans to the laptop's embedded
/// controller ("auto"), anything else locks them at that exact speed with no
/// automatic response to heat. A locked low speed is therefore dangerous, so this
/// curve is built around one rule:
///
///   <b>The coolest band is auto, never a low fixed speed.</b>
///
/// That means the curve only ever commands a speed <i>upward</i> from a reading that
/// justifies it. It can never be the reason the fans are sitting low while the chip
/// is hot, because "low" is not a speed it can command - "low" is handing control
/// back to the hardware that was managing the fans before BladeBoost existed.
///
/// Three consequences fall out of that rule, all of them wanted:
///   * A missing or failed sensor read means auto, not a guess.
///   * If BladeBoost is force-killed while cool, the fans were already on auto.
///   * Steps up are immediate; steps down wait, so it cannot oscillate.
/// </summary>
public sealed class FanCurve
{
    /// <summary>A band: at or above <paramref name="Temp"/>, ask for <paramref name="Rpm"/>.</summary>
    private sealed record Band(int Temp, int Rpm);

    // Driven from whichever sensor is asking for more. The CPU is the one that
    // actually surprises you: background work (a restore point, a virus scan) heats
    // it while the GPU sits idle. The GPU bands sit a little higher because a GPU at
    // 70C is normal under load, whereas a CPU at 70C on this laptop means something
    // unexpected is running.
    private static readonly Band[] CpuBands =
    [
        new(82, 5200),
        new(75, 4800),
        new(68, 4200),
    ];

    private static readonly Band[] GpuBands =
    [
        new(81, 5200),
        new(76, 4800),
        new(70, 4200),
    ];

    /// <summary>How far a temperature must fall below a band before leaving it.</summary>
    private const int DropMargin = 5;

    /// <summary>How long it must stay down there. Stops the curve hunting.</summary>
    private const int DropTicks = 30;

    /// <summary>
    /// How many consecutive readings must call for more air before the fans move up.
    ///
    /// This is not caution for its own sake. On this laptop a single-core turbo boost
    /// lasting a few milliseconds puts the package sensor into the 70s or 80s while
    /// the machine is sitting idle, so one sample is not evidence of heat. Proven on
    /// 4 Nov 2026: an idle spike to exactly 68C held the fans at 4,200 RPM for 44
    /// seconds for nothing.
    ///
    /// Three seconds is still far quicker than the thermal mass of a laptop, and the
    /// 92C/85C cutout is checked separately on every single reading, so nothing here
    /// delays the response to a genuine emergency.
    /// </summary>
    private const int RiseTicks = 3;

    /// <summary>
    /// Without a usable reading for this many ticks, fall back to auto. This is the
    /// watchdog: if the sensors die while the curve happens to be holding a speed,
    /// the fans must not stay pinned there indefinitely.
    /// </summary>
    private const int BlindTicks = 10;

    private int _target;
    private int _coolTicks;
    private int _riseTicks;
    private int _blindTicks;

    /// <summary>The speed the curve currently wants. 0 means automatic.</summary>
    public int Target => _target;

    /// <summary>Forgets its state, so a re-enable starts from automatic.</summary>
    public void Reset()
    {
        _target = 0;
        _coolTicks = 0;
        _riseTicks = 0;
        _blindTicks = 0;
    }

    /// <summary>
    /// Works out the fan speed for this reading. Returns 0 for automatic.
    /// Call once per sensor tick.
    /// </summary>
    public int Next(float? cpuTemp, float? gpuTemp)
    {
        var demand = Demand(cpuTemp, gpuTemp);

        // No reading at all. Hold briefly in case it is a single failed poll, then
        // give the fans back to the hardware rather than sit on a stale number.
        if (demand == null)
        {
            if (_target != 0 && ++_blindTicks >= BlindTicks)
            {
                Log.Write("Fan curve: no temperature for 10 seconds, fans back to automatic");
                Reset();
            }
            return _target;
        }
        _blindTicks = 0;

        // Up once a few readings agree. The count is of consecutive readings asking
        // for *more* than we are giving, not of one particular speed, so a fast climb
        // (69C, then 76C, then 83C) still reaches full speed on the third reading
        // rather than restarting the count each time the number changes.
        if (demand > _target)
        {
            _coolTicks = 0;
            if (++_riseTicks >= RiseTicks)
            {
                _riseTicks = 0;
                _target = demand.Value;
            }
            return _target;
        }
        _riseTicks = 0;

        // Down only after it has genuinely settled below the band we are holding.
        if (demand < _target)
        {
            if (++_coolTicks >= DropTicks)
            {
                _coolTicks = 0;
                _target = demand.Value;
            }
            return _target;
        }

        _coolTicks = 0;
        return _target;
    }

    /// <summary>
    /// The speed the temperatures are asking for, or null if neither sensor could be
    /// read. Applies <see cref="DropMargin"/> so a band is not left the instant the
    /// temperature dips a fraction below its entry point.
    /// </summary>
    private int? Demand(float? cpuTemp, float? gpuTemp)
    {
        if (!cpuTemp.HasValue && !gpuTemp.HasValue)
        {
            return null;
        }
        return Math.Max(Demand(cpuTemp, CpuBands), Demand(gpuTemp, GpuBands));
    }

    private int Demand(float? temp, Band[] bands)
    {
        if (!temp.HasValue)
        {
            return 0;
        }
        foreach (var band in bands)
        {
            // Holding this band already? Then it takes DropMargin to fall out of it.
            var threshold = _target >= band.Rpm ? band.Temp - DropMargin : band.Temp;
            if (temp.Value >= threshold)
            {
                return band.Rpm;
            }
        }
        return 0;
    }

    /// <summary>Plain-English description of the curve, for the window.</summary>
    public static string Describe() =>
        "Smart fans: automatic below 68°C, then 4,200 RPM from 68°C, 4,800 from 75°C "
        + "and full speed from 82°C (GPU: 70, 76 and 81°C). It speeds up after 3 seconds "
        + "of real heat, so a brief turbo spike at idle is ignored, and eases off only "
        + "after 30 seconds of cooler running. Any time a temperature cannot be read it "
        + "returns the fans to automatic.";
}
