using System;
using System.Runtime.InteropServices;

namespace BladeBoost.Core;

internal static class Privileges
{
    /// <summary>Turns on a privilege the (administrator) process already holds, e.g. to manage memory lists.</summary>
    public static bool Enable(string privilege)
    {
        if (!NativeMethods.OpenProcessToken(NativeMethods.GetCurrentProcess(),
                NativeMethods.TOKEN_ADJUST_PRIVILEGES | NativeMethods.TOKEN_QUERY, out var token))
        {
            return false;
        }
        try
        {
            if (!NativeMethods.LookupPrivilegeValue(null, privilege, out var luid))
            {
                return false;
            }
            var state = new NativeMethods.TOKEN_PRIVILEGES
            {
                PrivilegeCount = 1,
                Luid = luid,
                Attributes = NativeMethods.SE_PRIVILEGE_ENABLED,
            };
            // AdjustTokenPrivileges "succeeds" even when a privilege is missing, so check the last error.
            return NativeMethods.AdjustTokenPrivileges(token, false, ref state, 0, IntPtr.Zero, IntPtr.Zero)
                && Marshal.GetLastWin32Error() == 0;
        }
        finally
        {
            NativeMethods.CloseHandle(token);
        }
    }
}
