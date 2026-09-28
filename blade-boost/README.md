# BladeBoost

A small Windows app to open **before you play**, built for the **Razer Blade 15 Base Model (Late 2020)**
so you can uninstall Razer Synapse.

- **Temperatures:** live CPU and GPU temperature, load, power and clock speed, with a 60-second graph.
- **Performance & fans:** Balanced / Gaming / Custom (CPU and GPU boost) and manual fan speed (3,600–5,200 RPM),
  the same settings Synapse had.
- **Game Mode:** one click pauses background services, closes background apps, frees cached memory and sets
  Windows to *Best performance*. **Restore** puts everything back exactly as it was.
- **Memory:** see how RAM is used and free cached memory on demand.
- **Tray icon:** minimize it while you play; fan settings and the temperature safety keep working.

## Download and first start

1. Open the repository's **Releases** page and download **BladeBoost.exe** from *"BladeBoost (latest build)"*.
2. Double-click it. Windows asks for administrator permission, which it needs to talk to the fan controller and pause services.
3. The first time, Windows SmartScreen may say *"Windows protected your PC"* because the app isn't code-signed.
   Click **More info → Run anyway**.
4. **CPU temperature** needs **PawnIO**, a free, Microsoft-signed sensor driver used by LibreHardwareMonitor and Fan Control.
   If the app shows a yellow banner, click **Install PawnIO**, then restart BladeBoost. The GPU temperature works without it.

Settings and a log file are stored in `%LOCALAPPDATA%\BladeBoost`.

## Safety

- **Manual fans never go below 3,600 RPM**, the minimum Razer allows on this model.
- With manual fans on, BladeBoost switches the fans to **full speed if the CPU reaches 92°C or the GPU 85°C**.
- When you **close** BladeBoost (or Windows shuts down), the fans go **back to automatic**, controlled by the laptop itself.
- If fan control ever fails, nothing breaks: the laptop keeps controlling the fans automatically, as it does without Synapse.
- Game Mode never touches security, networking, audio, display or driver services. A Windows restart also brings every paused service back.

## Uninstalling Razer Synapse

1. Close Synapse (right-click its tray icon → *Exit*).
2. **Settings → Apps → Installed apps** → uninstall **Razer Synapse**, then also **Razer Central**, **Razer Chroma SDK** and
   **Razer Cortex** if they're listed.
3. Restart the laptop.

Without Synapse, the keyboard lighting goes back to its built-in default effect, and the fans keep running automatically.
Performance mode and fan speed are set in BladeBoost from now on.

## Honest notes about "clearing RAM"

Windows already hands free and cached memory to whatever game you start, so RAM cleaners rarely add FPS.
What actually frees memory is closing apps you don't need, which is what Game Mode does. *Free cached memory*
and *Trim app memory* are there if you want them. Trimming can cause a short stutter while apps reload what they need.

## Build it yourself

```bash
dotnet publish blade-boost/src/BladeBoost/BladeBoost.csproj -c Release -r win-x64 -o out
```

Requires the .NET 10 SDK. It also builds on Linux and macOS thanks to `EnableWindowsTargeting`.
Every push that changes `blade-boost/` rebuilds the download automatically (`.github/workflows/blade-boost.yml`).

## Credits

- Fan and performance protocol: [razer-laptop-control](https://github.com/Razer-Linux/razer-laptop-control-no-dkms)
  (lists this laptop as USB PID `0268`, fan range 3,600–5,200 RPM) and [razer-ctl](https://github.com/tdakhran/razer-ctl).
- Sensors: [LibreHardwareMonitor](https://github.com/LibreHardwareMonitor/LibreHardwareMonitor) (MPL-2.0)
  with the [PawnIO](https://pawnio.eu/) driver.

BladeBoost isn't made or endorsed by Razer. Razer and Razer Blade are trademarks of Razer Inc.
