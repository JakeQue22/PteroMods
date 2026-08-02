<div class="space-y-6">
    <section class="rounded-xl border border-slate-700 bg-slate-800 p-6 text-white shadow-md">
        <h1 class="text-2xl font-semibold">Configuration</h1>
        <p class="mt-2 text-sm text-slate-300">Browse DayZ configuration files with structured categories and editor capabilities.</p>
    </section>

    @foreach ($files as $category => $entries)
        <section class="rounded-xl border border-slate-700 bg-slate-800 p-6 text-white shadow-md">
            <h2 class="text-lg font-semibold">{{ $category }}</h2>
            <ul class="mt-4 space-y-2 text-sm text-slate-200">
                @forelse ($entries as $entry)
                    <li>{{ $entry }}</li>
                @empty
                    <li>No files matched this category.</li>
                @endforelse
            </ul>
        </section>
    @endforeach
</div>
