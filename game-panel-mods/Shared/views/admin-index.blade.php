<div class="space-y-6">
    <header class="rounded-xl bg-slate-900 p-6 text-white shadow-lg">
        <h1 class="text-2xl font-semibold">Game Panel Mods</h1>
        <p class="mt-2 text-sm text-slate-200">Manage installable panel modules independently without touching core panel files.</p>
    </header>

    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($modules as $module)
            @include('game-panel-mods.Shared.components.module-card', ['module' => $module])
        @endforeach
    </section>
</div>
