using System;
using System.Runtime.InteropServices;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Threading;
using BladeBoost.Core;

namespace BladeBoost;

public partial class App : Application
{
    private Mutex? _singleInstance;

    public App()
    {
        // Registered before the XAML resources load, so even a startup failure is reported instead of silently exiting.
        AppDomain.CurrentDomain.UnhandledException += (_, args) =>
        {
            Log.Write($"FATAL {args.ExceptionObject}");
            var message = (args.ExceptionObject as Exception)?.Message ?? args.ExceptionObject?.ToString() ?? "Unknown error";
            NativeMessageBox($"BladeBoost couldn't start:\n\n{message}\n\nDetails were saved to {AppPaths.LogFile}");
        };
    }

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
        TaskScheduler.UnobservedTaskException += (_, args) =>
        {
            Log.Error("background task", args.Exception);
            args.SetObserved();
        };

        Log.Write("BladeBoost started");
        base.OnStartup(e);

        try
        {
            var window = new MainWindow();
            MainWindow = window;
            window.Show();
        }
        catch (Exception ex)
        {
            Log.Error("opening the window", ex);
            MessageBox.Show($"BladeBoost couldn't open its window:\n\n{ex.Message}\n\nDetails were saved to {AppPaths.LogFile}",
                "BladeBoost", MessageBoxButton.OK, MessageBoxImage.Error);
            Shutdown(1);
        }
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

    [DllImport("user32.dll", CharSet = CharSet.Unicode, EntryPoint = "MessageBoxW")]
    private static extern int MessageBoxNative(IntPtr owner, string text, string caption, uint type);

    /// <summary>Plain Windows message box that works even when WPF itself failed to load.</summary>
    private static void NativeMessageBox(string text)
    {
        try
        {
            MessageBoxNative(IntPtr.Zero, text, "BladeBoost", 0x10 /* MB_ICONERROR */);
        }
        catch
        {
            // Nothing else we can do.
        }
    }
}
