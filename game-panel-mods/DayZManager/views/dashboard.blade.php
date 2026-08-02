<div class="grid gap-4 lg:grid-cols-3">
    @foreach ([
        'Server Name' => $server_name,
        'Current Map' => $current_map,
        'Server Version' => $server_version,
        'Installed Mods' => $installed_mods,
        'Player Count' => $player_count,
        'CPU' => $cpu,
        'RAM' => $ram,
        'Disk' => $disk,
        'Server Status' => $server_status,
    ] as $label => $value)
        <article class="rounded-xl border border-slate-700 bg-slate-800 p-5 text-white shadow-md">
            <p class="text-sm uppercase tracking-wide text-slate-400">{{ $label }}</p>
            <p class="mt-2 text-2xl font-semibold">{{ $value }}</p>
        </article>
    @endforeach
</div>
