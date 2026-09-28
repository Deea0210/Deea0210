using System;
using System.Collections.Generic;
using System.Collections.ObjectModel;
using System.Diagnostics;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Input;
using System.Windows.Media;
using BladeBoost.Core;
using BladeBoost.GameMode;
using BladeBoost.Hardware;

namespace BladeBoost;

public sealed class MainViewModel : ObservableObject, IDisposable
{
    private const int GraphPoints = 60;
    private const double GraphWidth = 260;
    private const double GraphHeight = 54;

    private static readonly Brush Cool = Freeze(new SolidColorBrush(Color.FromRgb(0x45, 0xD4, 0x83)));
    private static readonly Brush Warm = Freeze(new SolidColorBrush(Color.FromRgb(0xF5, 0xB8, 0x3D)));
    private static readonly Brush Hot = Freeze(new SolidColorBrush(Color.FromRgb(0xFF, 0x5D, 0x5D)));
    private static readonly Brush Unknown = Freeze(new SolidColorBrush(Color.FromRgb(0x6B, 0x74, 0x86)));

    private readonly Settings _settings = Settings.Load();
    private readonly GameModeService _gameMode = new();
    private readonly Queue<double> _cpuHistory = new();
    private readonly Queue<double> _gpuHistory = new();
    private readonly CancellationTokenSource _stop = new();
    private readonly object _laptopGate = new();

    private RazerLaptop? _laptop;
    private SensorService? _sensors;
    private volatile bool _manualFansActive;
    private volatile bool _safetyTriggered;
    private PerformanceMode _appliedMode = PerformanceMode.Balanced;

    public MainViewModel()
    {
        _mode = Enum.TryParse<PerformanceMode>(_settings.PerformanceMode, out var mode) ? mode : PerformanceMode.Balanced;
        _cpuBoost = GameModeService.ParseBoost(_settings.CpuBoost);
        _gpuBoost = GameModeService.ParseBoost(_settings.GpuBoost);
        _manualFans = _settings.ManualFans;
        _fanRpm = _settings.FanRpm;
        _extraApps = _settings.ExtraApps;
        _reopenApps = _settings.ReopenApps;
        _bestPerformancePower = _settings.BestPerformancePower;
        _gamingPerformanceMode = _settings.GamingPerformanceMode;
        _purgeStandby = _settings.PurgeStandbyMemory;
        _trimAppMemory = _settings.TrimAppMemory;
        _isGameModeActive = GameModeService.IsActive;

        ApplyCommand = new RelayCommand(() => _ = ApplyPerformanceAsync(), () => IsLaptopConnected && !IsBusy);
        MaxFansCommand = new RelayCommand(() => { ManualFans = true; FanRpm = FanMax; _ = ApplyPerformanceAsync(); }, () => IsLaptopConnected && !IsBusy);
        AutoFansCommand = new RelayCommand(() => { ManualFans = false; _ = ApplyPerformanceAsync(); }, () => IsLaptopConnected && !IsBusy);
        StartGameModeCommand = new RelayCommand(() => _ = StartGameModeAsync(), () => !IsBusy);
        RestoreCommand = new RelayCommand(() => _ = RestoreGameModeAsync(), () => !IsBusy && IsGameModeActive);
        PurgeStandbyCommand = new RelayCommand(() => _ = RunMemoryAsync(true), () => !IsBusy);
        TrimMemoryCommand = new RelayCommand(() => _ = RunMemoryAsync(false), () => !IsBusy);
        InstallPawnIoCommand = new RelayCommand(InstallPawnIo);
        ToggleDetailsCommand = new RelayCommand(() => ShowDetails = !ShowDetails);
        OpenLogCommand = new RelayCommand(() => OpenFile(AppPaths.LogFile));

        LoadGameModeLists();
        if (IsGameModeActive)
        {
            GameModeLog.Add("Game Mode is still on from last time. Press Restore to bring everything back.");
        }
    }

    // ---------- Startup / shutdown ----------

    public void Start()
    {
        Privileges.Enable("SeProfileSingleProcessPrivilege");
        PawnIoMissing = !SensorService.IsPawnIoInstalled();

        Task.Run(ConnectLaptop);
        Task.Run(SensorLoop);
    }

    private void ConnectLaptop()
    {
        try
        {
            var laptop = RazerLaptop.Find(out var message);
            var state = laptop?.ReadState();
            Ui(() =>
            {
                lock (_laptopGate)
                {
                    _laptop = laptop;
                }
                DeviceStatus = message;
                IsLaptopConnected = laptop != null;
                if (laptop != null)
                {
                    FanMin = laptop.Model.FanMin;
                    FanMax = laptop.Model.FanMax;
                    FanRpm = Math.Clamp(FanRpm, FanMin, FanMax);
                    CanCpuBoost = laptop.Model.HasCpuBoost;
                    if (state != null)
                    {
                        _appliedMode = state.Mode;
                        Mode = state.Mode is PerformanceMode.Creator ? PerformanceMode.Gaming : state.Mode;
                        _manualFansActive = state.ManualFans;
                        ManualFans = state.ManualFans;
                        if (state.ManualFans && state.FanRpm > 0)
                        {
                            FanRpm = Math.Clamp(state.FanRpm, FanMin, FanMax);
                        }
                        UpdateFanText(state);
                    }
                }
                StatusText = laptop != null ? "Ready." : "Temperatures and Game Mode work; fan control needs a supported Razer Blade.";
            });
        }
        catch (Exception ex)
        {
            Log.Error("connecting to laptop", ex);
            Ui(() => DeviceStatus = "Couldn't connect to the Razer controller");
        }
    }

    private async Task SensorLoop()
    {
        _sensors = new SensorService();
        var tick = 0;
        while (!_stop.IsCancellationRequested)
        {
            // Each part is read separately so one failing source doesn't blank the others.
            var reading = SensorReading.Empty;
            try
            {
                reading = _sensors.Read();
            }
            catch (Exception ex)
            {
                LogOnce("reading sensors", ex);
            }

            MemoryInfo? memory = null;
            try
            {
                memory = MemoryManager.Read();
            }
            catch (Exception ex)
            {
                LogOnce("reading memory", ex);
            }

            RazerState? state = null;
            if (tick++ % 3 == 0)
            {
                try
                {
                    lock (_laptopGate)
                    {
                        state = _laptop?.ReadState();
                    }
                }
                catch (Exception ex)
                {
                    LogOnce("reading fan state", ex);
                }
            }

            try
            {
                CheckSafety(reading);
            }
            catch (Exception ex)
            {
                LogOnce("fan safety", ex);
            }

            Ui(() => ShowReading(reading, memory, state));

            try
            {
                await Task.Delay(1000, _stop.Token);
            }
            catch (TaskCanceledException)
            {
                break;
            }
        }
    }

    private readonly HashSet<string> _loggedErrors = new();

    /// <summary>Logs a repeating background error once instead of every second.</summary>
    private void LogOnce(string context, Exception ex)
    {
        if (_loggedErrors.Add(context + ex.GetType().Name + ex.Message))
        {
            Log.Error(context, ex);
        }
    }

    /// <summary>Called when the app exits: fans go back to automatic so the laptop manages them again.</summary>
    public void Shutdown()
    {
        _stop.Cancel();
        SaveSettings();
        lock (_laptopGate)
        {
            if (_laptop != null && (_manualFansActive || _safetyTriggered))
            {
                try
                {
                    _laptop.Apply(_appliedMode, 0, CpuBoost, GpuBoost);
                    Log.Write("Exit: fans returned to automatic");
                }
                catch (Exception ex)
                {
                    Log.Error("returning fans to automatic", ex);
                }
            }
        }
    }

    public void Dispose()
    {
        _stop.Cancel();
        lock (_laptopGate)
        {
            _laptop?.Dispose();
            _laptop = null;
        }
        _sensors?.Dispose();
    }

    // ---------- Sensors ----------

    private void ShowReading(SensorReading r, MemoryInfo? m, RazerState? state)
    {
        CpuName = Shorten(r.CpuName);
        GpuName = Shorten(r.GpuName);
        CpuTempText = r.CpuTemp.HasValue ? $"{r.CpuTemp:0}°" : "—";
        GpuTempText = r.GpuTemp.HasValue ? $"{r.GpuTemp:0}°" : "—";
        CpuTempBrush = BrushFor(r.CpuTemp, 75, 88);
        GpuTempBrush = BrushFor(r.GpuTemp, 72, 83);
        CpuDetail = $"{Pct(r.CpuLoad)} load" + (r.CpuPower.HasValue ? $" · {r.CpuPower:0} W" : "");
        GpuDetail = $"{Pct(r.GpuLoad)} load" + (r.GpuClock.HasValue ? $" · {r.GpuClock:0} MHz" : "");
        CpuGraph = Push(_cpuHistory, r.CpuTemp);
        GpuGraph = Push(_gpuHistory, r.GpuTemp);
        CpuTempMissing = !r.CpuTemp.HasValue;

        if (m != null)
        {
            MemoryPercent = m.LoadPercent;
            MemoryUsedText = $"{m.InUseGb:0.0} GB of {m.TotalGb:0} GB in use";
            MemoryDetail = $"{m.StandbyGb:0.0} GB cached (standby) · {m.FreeGb:0.0} GB free";
        }

        if (state != null)
        {
            UpdateFanText(state);
        }
    }

    private void UpdateFanText(RazerState state)
    {
        FanText = state.ManualFans
            ? $"Fans: {Math.Max(state.FanRpm, FanMin):N0} RPM (manual)"
            : state.FanRpm > 0 ? $"Fans: automatic (~{state.FanRpm:N0} RPM)" : "Fans: automatic";
        LaptopModeText = $"Razer mode: {state.Mode}";
    }

    /// <summary>With manual fans, jump to full speed if the CPU or GPU gets too hot.</summary>
    private void CheckSafety(SensorReading r)
    {
        if (!_manualFansActive || _safetyTriggered)
        {
            return;
        }
        var cpuHot = r.CpuTemp >= _settings.SafetyCpuTemp;
        var gpuHot = r.GpuTemp >= _settings.SafetyGpuTemp;
        if (!cpuHot && !gpuHot)
        {
            return;
        }
        lock (_laptopGate)
        {
            if (_laptop == null)
            {
                return;
            }
            _laptop.Apply(_appliedMode, _laptop.Model.FanMax, CpuBoost, GpuBoost);
            _safetyTriggered = true;
            var reason = cpuHot ? $"CPU reached {r.CpuTemp:0}°C" : $"GPU reached {r.GpuTemp:0}°C";
            Log.Write($"Safety: {reason}, fans set to maximum");
            Ui(() =>
            {
                FanRpm = FanMax;
                StatusText = $"Safety: {reason}, fans set to maximum.";
            });
        }
    }

    // ---------- Performance ----------

    private async Task ApplyPerformanceAsync()
    {
        if (_laptop == null)
        {
            return;
        }
        IsBusy = true;
        var mode = Mode;
        var rpm = ManualFans && mode != PerformanceMode.Custom ? FanRpm : 0;
        var cpu = CpuBoost;
        var gpu = GpuBoost;
        StatusText = "Applying…";
        var ok = await Task.Run(() =>
        {
            lock (_laptopGate)
            {
                var result = _laptop.Apply(mode, rpm, cpu, gpu);
                var state = _laptop.ReadState();
                if (state != null)
                {
                    Ui(() => UpdateFanText(state));
                }
                return result;
            }
        });
        _appliedMode = mode;
        _manualFansActive = rpm > 0;
        _safetyTriggered = false;
        SaveSettings();
        StatusText = ok
            ? $"Applied: {mode} mode, " + (rpm > 0 ? $"fans at {rpm:N0} RPM. Keep BladeBoost open (minimized is fine) while you play." : "automatic fans.")
            : "The laptop didn't accept every setting. See the log for details.";
        IsBusy = false;
    }

    // ---------- Game Mode ----------

    private void LoadGameModeLists()
    {
        Services.Clear();
        foreach (var s in Catalog.InstalledServices())
        {
            var on = _settings.Services.TryGetValue(s.Name, out var saved) ? saved : s.DefaultOn;
            Services.Add(new OptionItem(s.Name, s.Title, s.Why, on));
        }
        Apps.Clear();
        foreach (var a in Catalog.Apps)
        {
            var on = _settings.Apps.TryGetValue(a.Key, out var saved) ? saved : a.DefaultOn;
            Apps.Add(new OptionItem(a.Key, a.Title, string.Join(", ", a.ProcessNames.Take(2)) + ".exe", on));
        }
    }

    private GameModeOptions CollectOptions()
    {
        var apps = Catalog.Apps.Where(a => Apps.Any(i => i.Key == a.Key && i.IsChecked))
            .Concat(Catalog.ParseExtraApps(ExtraApps))
            .ToList();
        return new GameModeOptions(
            Services.Where(s => s.IsChecked).Select(s => s.Key).ToList(),
            apps,
            BestPerformancePower,
            GamingPerformanceMode,
            PurgeStandby,
            TrimAppMemory,
            ReopenApps);
    }

    private async Task StartGameModeAsync()
    {
        SaveSettings();
        IsBusy = true;
        StatusText = "Starting Game Mode…";
        GameModeLog.Clear();
        var options = CollectOptions();
        var laptop = _laptop;
        var lines = await Task.Run(() => _gameMode.Start(options, laptop, _settings));
        foreach (var line in lines)
        {
            GameModeLog.Add(line);
        }
        if (options.GamingPerformanceMode && _laptop != null)
        {
            _appliedMode = PerformanceMode.Gaming;
            _manualFansActive = ManualFans;
            _safetyTriggered = false;
            Mode = PerformanceMode.Gaming;
        }
        IsGameModeActive = GameModeService.IsActive;
        StatusText = "Game Mode is on. Have fun!";
        IsBusy = false;
    }

    private async Task RestoreGameModeAsync()
    {
        IsBusy = true;
        StatusText = "Restoring…";
        GameModeLog.Clear();
        var laptop = _laptop;
        var lines = await Task.Run(() =>
        {
            var result = _gameMode.Restore(laptop, _settings);
            var state = laptop?.ReadState();
            if (state != null)
            {
                Ui(() =>
                {
                    _appliedMode = state.Mode;
                    _manualFansActive = state.ManualFans;
                    Mode = state.Mode is PerformanceMode.Creator ? PerformanceMode.Gaming : state.Mode;
                    UpdateFanText(state);
                });
            }
            return result;
        });
        foreach (var line in lines)
        {
            GameModeLog.Add(line);
        }
        IsGameModeActive = GameModeService.IsActive;
        StatusText = "Restored. Everything is back to how it was.";
        IsBusy = false;
    }

    private async Task RunMemoryAsync(bool purgeStandby)
    {
        IsBusy = true;
        var before = MemoryManager.Read();
        var ok = await Task.Run(() => purgeStandby ? MemoryManager.PurgeStandbyList() : MemoryManager.TrimWorkingSets());
        var after = MemoryManager.Read();
        StatusText = !ok
            ? "Windows didn't allow that. See the log for details."
            : purgeStandby
                ? $"Freed {Math.Max(0, after.FreeGb - before.FreeGb):0.0} GB of cached memory."
                : $"Trimmed app memory: {Math.Max(0, before.InUseGb - after.InUseGb):0.0} GB released (apps take back what they need).";
        IsBusy = false;
    }

    public void SaveSettings()
    {
        _settings.PerformanceMode = Mode.ToString();
        _settings.CpuBoost = CpuBoost.ToString();
        _settings.GpuBoost = GpuBoost.ToString();
        _settings.ManualFans = ManualFans;
        _settings.FanRpm = FanRpm;
        _settings.ExtraApps = ExtraApps;
        _settings.ReopenApps = ReopenApps;
        _settings.BestPerformancePower = BestPerformancePower;
        _settings.GamingPerformanceMode = GamingPerformanceMode;
        _settings.PurgeStandbyMemory = PurgeStandby;
        _settings.TrimAppMemory = TrimAppMemory;
        foreach (var s in Services)
        {
            _settings.Services[s.Key] = s.IsChecked;
        }
        foreach (var a in Apps)
        {
            _settings.Apps[a.Key] = a.IsChecked;
        }
        _settings.Save();
    }

    public void RestoreFromTray()
    {
        if (RestoreCommand.CanExecute(null))
        {
            RestoreCommand.Execute(null);
        }
    }

    // ---------- PawnIO ----------

    private void InstallPawnIo()
    {
        try
        {
            Process.Start(new ProcessStartInfo("cmd.exe",
                "/k echo Installing PawnIO (free, signed sensor driver used for CPU temperatures)... && " +
                "winget install --id namazso.PawnIO -e --accept-package-agreements --accept-source-agreements && " +
                "echo. && echo Done. Close this window and restart BladeBoost.")
            { UseShellExecute = true });
            StatusText = "Installing PawnIO in a separate window. Restart BladeBoost when it finishes.";
        }
        catch (Exception ex)
        {
            Log.Error("installing PawnIO", ex);
            OpenUrl("https://pawnio.eu/");
        }
    }

    private static void OpenUrl(string url)
    {
        try
        {
            Process.Start(new ProcessStartInfo(url) { UseShellExecute = true });
        }
        catch (Exception ex)
        {
            Log.Error("opening " + url, ex);
        }
    }

    private static void OpenFile(string path)
    {
        try
        {
            if (System.IO.File.Exists(path))
            {
                Process.Start(new ProcessStartInfo("notepad.exe", $"\"{path}\"") { UseShellExecute = true });
            }
        }
        catch (Exception ex)
        {
            Log.Error("opening log", ex);
        }
    }

    // ---------- Helpers ----------

    private static void Ui(Action action)
    {
        var dispatcher = Application.Current?.Dispatcher;
        if (dispatcher == null || dispatcher.HasShutdownStarted)
        {
            return;
        }
        if (dispatcher.CheckAccess())
        {
            action();
        }
        else
        {
            dispatcher.BeginInvoke(action);
        }
    }

    private static Brush Freeze(SolidColorBrush brush)
    {
        brush.Freeze();
        return brush;
    }

    private static Brush BrushFor(float? temp, float warm, float hot) =>
        !temp.HasValue ? Unknown : temp >= hot ? Hot : temp >= warm ? Warm : Cool;

    private static string Pct(float? value) => value.HasValue ? $"{value:0}%" : "—";

    private static string Shorten(string name) =>
        name.Replace("Intel Core ", "").Replace("NVIDIA GeForce ", "").Replace("(R)", "").Replace("(TM)", "").Trim();

    private static PointCollection Push(Queue<double> history, float? value)
    {
        if (value.HasValue)
        {
            history.Enqueue(value.Value);
            while (history.Count > GraphPoints)
            {
                history.Dequeue();
            }
        }
        var points = new PointCollection();
        var i = GraphPoints - history.Count;
        foreach (var v in history)
        {
            var x = i * GraphWidth / (GraphPoints - 1);
            var y = GraphHeight - Math.Clamp((v - 30) / 70, 0, 1) * GraphHeight; // 30–100 °C
            points.Add(new Point(x, y));
            i++;
        }
        points.Freeze();
        return points;
    }

    // ---------- Bindable properties ----------

    public RelayCommand ApplyCommand { get; }
    public RelayCommand MaxFansCommand { get; }
    public RelayCommand AutoFansCommand { get; }
    public RelayCommand StartGameModeCommand { get; }
    public RelayCommand RestoreCommand { get; }
    public RelayCommand PurgeStandbyCommand { get; }
    public RelayCommand TrimMemoryCommand { get; }
    public RelayCommand InstallPawnIoCommand { get; }
    public RelayCommand ToggleDetailsCommand { get; }
    public RelayCommand OpenLogCommand { get; }

    public ObservableCollection<OptionItem> Services { get; } = new();
    public ObservableCollection<OptionItem> Apps { get; } = new();
    public ObservableCollection<string> GameModeLog { get; } = new();

    private string _deviceStatus = "Looking for the Razer controller…";
    public string DeviceStatus { get => _deviceStatus; set => Set(ref _deviceStatus, value); }

    private bool _isLaptopConnected;
    public bool IsLaptopConnected { get => _isLaptopConnected; set => Set(ref _isLaptopConnected, value); }

    private bool _pawnIoMissing;
    public bool PawnIoMissing { get => _pawnIoMissing; set { if (Set(ref _pawnIoMissing, value)) OnPropertyChanged(nameof(ShowPawnIoBanner)); } }

    private bool _cpuTempMissing;
    public bool CpuTempMissing { get => _cpuTempMissing; set { if (Set(ref _cpuTempMissing, value)) OnPropertyChanged(nameof(ShowPawnIoBanner)); } }

    public bool ShowPawnIoBanner => PawnIoMissing && CpuTempMissing;

    private string _statusText = "Starting…";
    public string StatusText { get => _statusText; set => Set(ref _statusText, value); }

    private bool _isBusy;
    public bool IsBusy { get => _isBusy; set { if (Set(ref _isBusy, value)) CommandManager.InvalidateRequerySuggested(); } }

    private string _cpuName = "";
    public string CpuName { get => _cpuName; set => Set(ref _cpuName, value); }

    private string _gpuName = "";
    public string GpuName { get => _gpuName; set => Set(ref _gpuName, value); }

    private string _cpuTempText = "—";
    public string CpuTempText { get => _cpuTempText; set => Set(ref _cpuTempText, value); }

    private string _gpuTempText = "—";
    public string GpuTempText { get => _gpuTempText; set => Set(ref _gpuTempText, value); }

    private Brush _cpuTempBrush = Unknown;
    public Brush CpuTempBrush { get => _cpuTempBrush; set => Set(ref _cpuTempBrush, value); }

    private Brush _gpuTempBrush = Unknown;
    public Brush GpuTempBrush { get => _gpuTempBrush; set => Set(ref _gpuTempBrush, value); }

    private string _cpuDetail = "";
    public string CpuDetail { get => _cpuDetail; set => Set(ref _cpuDetail, value); }

    private string _gpuDetail = "";
    public string GpuDetail { get => _gpuDetail; set => Set(ref _gpuDetail, value); }

    private PointCollection _cpuGraph = new();
    public PointCollection CpuGraph { get => _cpuGraph; set => Set(ref _cpuGraph, value); }

    private PointCollection _gpuGraph = new();
    public PointCollection GpuGraph { get => _gpuGraph; set => Set(ref _gpuGraph, value); }

    private string _fanText = "Fans: —";
    public string FanText { get => _fanText; set => Set(ref _fanText, value); }

    private string _laptopModeText = "";
    public string LaptopModeText { get => _laptopModeText; set => Set(ref _laptopModeText, value); }

    private double _memoryPercent;
    public double MemoryPercent { get => _memoryPercent; set => Set(ref _memoryPercent, value); }

    private string _memoryUsedText = "";
    public string MemoryUsedText { get => _memoryUsedText; set => Set(ref _memoryUsedText, value); }

    private string _memoryDetail = "";
    public string MemoryDetail { get => _memoryDetail; set => Set(ref _memoryDetail, value); }

    // Performance mode (bound to three radio buttons)
    private PerformanceMode _mode;
    public PerformanceMode Mode
    {
        get => _mode;
        set
        {
            if (Set(ref _mode, value))
            {
                OnPropertyChanged(nameof(IsBalanced));
                OnPropertyChanged(nameof(IsGaming));
                OnPropertyChanged(nameof(IsCustom));
                OnPropertyChanged(nameof(CanUseManualFans));
            }
        }
    }
    public bool IsBalanced { get => Mode == PerformanceMode.Balanced; set { if (value) Mode = PerformanceMode.Balanced; } }
    public bool IsGaming { get => Mode == PerformanceMode.Gaming; set { if (value) Mode = PerformanceMode.Gaming; } }
    public bool IsCustom { get => Mode == PerformanceMode.Custom; set { if (value) Mode = PerformanceMode.Custom; } }

    private BoostLevel _cpuBoost;
    public BoostLevel CpuBoost
    {
        get => _cpuBoost;
        set
        {
            if (Set(ref _cpuBoost, value))
            {
                OnPropertyChanged(nameof(CpuLow));
                OnPropertyChanged(nameof(CpuMedium));
                OnPropertyChanged(nameof(CpuHigh));
                OnPropertyChanged(nameof(CpuBoostMax));
            }
        }
    }
    public bool CpuLow { get => CpuBoost == BoostLevel.Low; set { if (value) CpuBoost = BoostLevel.Low; } }
    public bool CpuMedium { get => CpuBoost == BoostLevel.Medium; set { if (value) CpuBoost = BoostLevel.Medium; } }
    public bool CpuHigh { get => CpuBoost == BoostLevel.High; set { if (value) CpuBoost = BoostLevel.High; } }
    public bool CpuBoostMax { get => CpuBoost == BoostLevel.Boost; set { if (value) CpuBoost = BoostLevel.Boost; } }

    private BoostLevel _gpuBoost;
    public BoostLevel GpuBoost
    {
        get => _gpuBoost;
        set
        {
            if (Set(ref _gpuBoost, value))
            {
                OnPropertyChanged(nameof(GpuLow));
                OnPropertyChanged(nameof(GpuMedium));
                OnPropertyChanged(nameof(GpuHigh));
            }
        }
    }
    public bool GpuLow { get => GpuBoost == BoostLevel.Low; set { if (value) GpuBoost = BoostLevel.Low; } }
    public bool GpuMedium { get => GpuBoost == BoostLevel.Medium; set { if (value) GpuBoost = BoostLevel.Medium; } }
    public bool GpuHigh { get => GpuBoost == BoostLevel.High; set { if (value) GpuBoost = BoostLevel.High; } }

    private bool _canCpuBoost;
    public bool CanCpuBoost { get => _canCpuBoost; set => Set(ref _canCpuBoost, value); }

    private bool _manualFans;
    public bool ManualFans { get => _manualFans; set { if (Set(ref _manualFans, value)) OnPropertyChanged(nameof(AutoFans)); } }
    public bool AutoFans { get => !ManualFans; set => ManualFans = !value; }

    public bool CanUseManualFans => Mode != PerformanceMode.Custom;

    private int _fanRpm;
    public int FanRpm { get => _fanRpm; set { if (Set(ref _fanRpm, value / 100 * 100)) { OnPropertyChanged(nameof(FanRpmText)); OnPropertyChanged(nameof(FanRpmSlider)); } } }
    public string FanRpmText => $"{FanRpm:N0} RPM";

    /// <summary>Slider-friendly view of <see cref="FanRpm"/> (sliders work in doubles).</summary>
    public double FanRpmSlider
    {
        get => FanRpm;
        set
        {
            FanRpm = (int)Math.Round(value / 100) * 100;
            OnPropertyChanged();
        }
    }

    private int _fanMin = 3600;
    public int FanMin { get => _fanMin; set => Set(ref _fanMin, value); }

    private int _fanMax = 5200;
    public int FanMax { get => _fanMax; set => Set(ref _fanMax, value); }

    public string SafetyText => $"Safety: with manual fans, BladeBoost switches to full speed if the CPU reaches {_settings.SafetyCpuTemp}°C or the GPU {_settings.SafetyGpuTemp}°C, and fans return to automatic when you close it.";

    // Game Mode options
    private bool _isGameModeActive;
    public bool IsGameModeActive { get => _isGameModeActive; set { if (Set(ref _isGameModeActive, value)) CommandManager.InvalidateRequerySuggested(); } }

    private bool _showDetails;
    public bool ShowDetails { get => _showDetails; set => Set(ref _showDetails, value); }

    private string _extraApps;
    public string ExtraApps { get => _extraApps; set => Set(ref _extraApps, value); }

    private bool _reopenApps;
    public bool ReopenApps { get => _reopenApps; set => Set(ref _reopenApps, value); }

    private bool _bestPerformancePower;
    public bool BestPerformancePower { get => _bestPerformancePower; set => Set(ref _bestPerformancePower, value); }

    private bool _gamingPerformanceMode;
    public bool GamingPerformanceMode { get => _gamingPerformanceMode; set => Set(ref _gamingPerformanceMode, value); }

    private bool _purgeStandby;
    public bool PurgeStandby { get => _purgeStandby; set => Set(ref _purgeStandby, value); }

    private bool _trimAppMemory;
    public bool TrimAppMemory { get => _trimAppMemory; set => Set(ref _trimAppMemory, value); }
}
