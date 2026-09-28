using System;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Threading;
using BladeBoost.Core;

namespace BladeBoost;

public partial class App : Application
{
    private Mutex? _singleInstance;

    protected override void OnStartup(StartupEventArgs e)
    {
        // Only one copy may talk to the fan controller at a time.
        _singleInstance = new Mutex(true, @"Global\BladeBoost.SingleInstance", out var isFirst);
        if (!isFirst)
        {
            MessageBox.Show("BladeBoost is already running. Look for its icon next to the clock.", "BladeBoost",
                MessageBoxButton.OK, MessageBoxImage.Information);
            Shutdown();
            return;
        }

        DispatcherUnhandledException += OnUiException;
        AppDomain.CurrentDomain.UnhandledException += (_, args) => Log.Write($"FATAL {args.ExceptionObject}");
        TaskScheduler.UnobservedTaskException += (_, args) =>
        {
            Log.Error("background task", args.Exception);
            args.SetObserved();
        };

        Log.Write("BladeBoost started");
        base.OnStartup(e);
        new MainWindow().Show();
    }

    private static void OnUiException(object sender, DispatcherUnhandledExceptionEventArgs e)
    {
        Log.Error("UI", e.Exception);
        MessageBox.Show($"Something went wrong:\n\n{e.Exception.Message}\n\nDetails were saved to {AppPaths.LogFile}",
            "BladeBoost", MessageBoxButton.OK, MessageBoxImage.Warning);
        e.Handled = true;
    }

    protected override void OnExit(ExitEventArgs e)
    {
        Log.Write("BladeBoost closed");
        _singleInstance?.Dispose();
        base.OnExit(e);
    }
}
