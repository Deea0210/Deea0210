using System;
using System.Runtime.InteropServices;
using BladeBoost.Core;

namespace BladeBoost.Hardware;

public sealed record MemoryInfo(double TotalGb, double InUseGb, double StandbyGb, double FreeGb, int LoadPercent);

/// <summary>
/// RAM usage, plus the two things "RAM cleaners" do: purge the standby list
/// (cached files) and trim app working sets. Windows refills both on demand,
/// so these are safe but their effect on games is usually small.
/// </summary>
internal static class MemoryManager
{
    private const double Gb = 1024d * 1024 * 1024;
    private const int PageSize = 4096;

    public static MemoryInfo Read()
    {
        var status = new NativeMethods.MEMORYSTATUSEX { dwLength = (uint)Marshal.SizeOf<NativeMethods.MEMORYSTATUSEX>() };
        NativeMethods.GlobalMemoryStatusEx(ref status);
        var total = status.ullTotalPhys / Gb;
        var available = status.ullAvailPhys / Gb;

        var (standby, free) = ReadMemoryLists();
        if (standby < 0)
        {
            standby = 0;
            free = available;
        }
        return new MemoryInfo(total, total - available, standby, free, (int)status.dwMemoryLoad);
    }

    /// <summary>Standby (cached) and free memory in GB, from the kernel's memory list counters.</summary>
    private static (double Standby, double Free) ReadMemoryLists()
    {
        // SYSTEM_MEMORY_LIST_INFORMATION: 24 pointer-sized counters.
        var size = IntPtr.Size * 24;
        var buffer = Marshal.AllocHGlobal(size);
        try
        {
            if (NativeMethods.NtQuerySystemInformation(NativeMethods.SystemMemoryListInformation, buffer, size, out _) != 0)
            {
                return (-1, -1);
            }
            long Read(int index) => Marshal.ReadIntPtr(buffer, index * IntPtr.Size).ToInt64();
            var free = Read(0) + Read(1); // zeroed + free pages
            long standby = 0;
            for (var i = 5; i < 13; i++)
            {
                standby += Read(i); // standby pages by priority 0..7
            }
            return (standby * (double)PageSize / Gb, free * (double)PageSize / Gb);
        }
        finally
        {
            Marshal.FreeHGlobal(buffer);
        }
    }

    public static bool PurgeStandbyList() => SetMemoryList(NativeMethods.MemoryPurgeStandbyList, "purge standby list");

    public static bool TrimWorkingSets() => SetMemoryList(NativeMethods.MemoryEmptyWorkingSets, "trim working sets");

    private static bool SetMemoryList(int command, string what)
    {
        Privileges.Enable("SeProfileSingleProcessPrivilege");
        Privileges.Enable("SeIncreaseQuotaPrivilege");
        var status = NativeMethods.NtSetSystemInformation(NativeMethods.SystemMemoryListInformation, ref command, sizeof(int));
        if (status != 0)
        {
            Log.Write($"Memory: could not {what} (NTSTATUS 0x{status:X8})");
        }
        return status == 0;
    }
}
