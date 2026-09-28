using System;
using System.Collections.Generic;
using System.Runtime.InteropServices;
using BladeBoost.Core;
using Microsoft.Win32.SafeHandles;

namespace BladeBoost.Hardware;

/// <summary>Minimal HID access: list devices and exchange feature reports.</summary>
internal sealed class HidDevice : IDisposable
{
    private readonly SafeFileHandle _handle;

    public string Path { get; }
    public int FeatureReportLength { get; }

    private HidDevice(string path, SafeFileHandle handle, int featureReportLength)
    {
        Path = path;
        _handle = handle;
        FeatureReportLength = featureReportLength;
    }

    /// <summary>
    /// Opens a HID collection without read/write access. Windows keeps keyboards
    /// exclusive, but feature reports (used for fan and power control) still work.
    /// </summary>
    public static HidDevice? Open(string path)
    {
        var handle = NativeMethods.CreateFile(path, 0,
            NativeMethods.FILE_SHARE_READ | NativeMethods.FILE_SHARE_WRITE,
            IntPtr.Zero, NativeMethods.OPEN_EXISTING, 0, IntPtr.Zero);
        if (handle.IsInvalid)
        {
            handle.Dispose();
            return null;
        }

        var featureLength = 0;
        if (NativeMethods.HidD_GetPreparsedData(handle, out var preparsed))
        {
            try
            {
                if (NativeMethods.HidP_GetCaps(preparsed, out var caps) == NativeMethods.HIDP_STATUS_SUCCESS)
                {
                    featureLength = caps.FeatureReportByteLength;
                }
            }
            finally
            {
                NativeMethods.HidD_FreePreparsedData(preparsed);
            }
        }
        return new HidDevice(path, handle, featureLength);
    }

    /// <summary>Device paths of every present HID collection, e.g. \\?\hid#vid_1532&amp;pid_0268&amp;mi_00#...</summary>
    public static List<string> EnumeratePaths()
    {
        var paths = new List<string>();
        NativeMethods.HidD_GetHidGuid(out var hidGuid);
        var set = NativeMethods.SetupDiGetClassDevs(ref hidGuid, IntPtr.Zero, IntPtr.Zero,
            NativeMethods.DIGCF_PRESENT | NativeMethods.DIGCF_DEVICEINTERFACE);
        if (set == IntPtr.Zero || set == new IntPtr(-1))
        {
            return paths;
        }
        try
        {
            var data = new NativeMethods.SP_DEVICE_INTERFACE_DATA { cbSize = Marshal.SizeOf<NativeMethods.SP_DEVICE_INTERFACE_DATA>() };
            for (uint i = 0; NativeMethods.SetupDiEnumDeviceInterfaces(set, IntPtr.Zero, ref hidGuid, i, ref data); i++)
            {
                NativeMethods.SetupDiGetDeviceInterfaceDetail(set, ref data, IntPtr.Zero, 0, out var required, IntPtr.Zero);
                if (required == 0)
                {
                    continue;
                }
                var buffer = Marshal.AllocHGlobal((int)required);
                try
                {
                    // SP_DEVICE_INTERFACE_DETAIL_DATA_W.cbSize: 8 on 64-bit, 6 on 32-bit.
                    Marshal.WriteInt32(buffer, IntPtr.Size == 8 ? 8 : 6);
                    if (NativeMethods.SetupDiGetDeviceInterfaceDetail(set, ref data, buffer, required, out _, IntPtr.Zero))
                    {
                        var path = Marshal.PtrToStringUni(buffer + 4);
                        if (!string.IsNullOrEmpty(path))
                        {
                            paths.Add(path);
                        }
                    }
                }
                finally
                {
                    Marshal.FreeHGlobal(buffer);
                }
            }
        }
        finally
        {
            NativeMethods.SetupDiDestroyDeviceInfoList(set);
        }
        return paths;
    }

    public bool SetFeature(byte[] report) => NativeMethods.HidD_SetFeature(_handle, report, report.Length);

    public bool GetFeature(byte[] report) => NativeMethods.HidD_GetFeature(_handle, report, report.Length);

    public void Dispose() => _handle.Dispose();
}
