using System;
using System.ComponentModel;
using System.Windows;
using Forms = System.Windows.Forms;

namespace BladeBoost;

public partial class MainWindow : Window
{
    private readonly MainViewModel _viewModel = new();
    private readonly Forms.NotifyIcon _trayIcon;
    private bool _exiting;
    private bool _trayHintShown;

    public MainWindow()
    {
        InitializeComponent();
        DataContext = _viewModel;

        _trayIcon = new Forms.NotifyIcon
        {
            Text = "BladeBoost",
            Icon = LoadIcon(),
            Visible = true,
            ContextMenuStrip = new Forms.ContextMenuStrip(),
        };
        _trayIcon.ContextMenuStrip.Items.Add("Open BladeBoost", null, (_, _) => ShowFromTray());
        _trayIcon.ContextMenuStrip.Items.Add("Restore Game Mode", null, (_, _) => _viewModel.RestoreFromTray());
        _trayIcon.ContextMenuStrip.Items.Add(new Forms.ToolStripSeparator());
        _trayIcon.ContextMenuStrip.Items.Add("Exit (fans back to automatic)", null, (_, _) => Close());
        _trayIcon.DoubleClick += (_, _) => ShowFromTray();

        Loaded += (_, _) => _viewModel.Start();
        StateChanged += OnStateChanged;
        Closing += OnClosing;

        // Windows is logging off or shutting down: hand the fans back to the laptop.
        Application.Current.SessionEnding += (_, _) => ShutdownOnce();
    }

    private static System.Drawing.Icon LoadIcon()
    {
        var resource = Application.GetResourceStream(new Uri("pack://application:,,,/Assets/BladeBoost.ico"));
        return resource != null ? new System.Drawing.Icon(resource.Stream) : System.Drawing.SystemIcons.Application;
    }

    private void OnStateChanged(object? sender, EventArgs e)
    {
        if (WindowState != WindowState.Minimized)
        {
            return;
        }
        Hide();
        if (!_trayHintShown)
        {
            _trayIcon.ShowBalloonTip(4000, "BladeBoost is still running",
                "Fan settings and the temperature safety stay active. Double-click the icon to open it.", Forms.ToolTipIcon.Info);
            _trayHintShown = true;
        }
    }

    private void ShowFromTray()
    {
        Show();
        WindowState = WindowState.Normal;
        Activate();
    }

    private void OnClosing(object? sender, CancelEventArgs e) => ShutdownOnce();

    private void ShutdownOnce()
    {
        if (_exiting)
        {
            return;
        }
        _exiting = true;
        _viewModel.Shutdown();
        _viewModel.Dispose();
        _trayIcon.Visible = false;
        _trayIcon.Dispose();
    }
}
