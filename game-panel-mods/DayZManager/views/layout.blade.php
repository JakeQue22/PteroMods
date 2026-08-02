<!DOCTYPE html>
<html lang="en" class="dz-html">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    @if (!empty($csrf_token))
        <meta name="csrf-token" content="{{ $csrf_token }}" />
    @endif
    <title>{{ $title }} &middot; {{ $server_name }}</title>
    <style>{!! $module_css !!}</style>
</head>
<body class="dz-body">
<div class="dz-shell">

    <header class="dz-topbar">
        <div>
            <h1>DayZ Manager</h1>
            <p>{{ $server_name }}{{ ($server_id !== '' && $server_id !== $server_name) ? ' · ' . $server_id : '' }}</p>
        </div>
        @if ($server_id !== '')
            <a class="dz-back" href="/server/{{ $server_id }}">&larr; Back to server</a>
        @endif
    </header>

    <nav class="dz-nav" aria-label="DayZ Manager navigation">
        @foreach ($tabs as $tab)
            <a href="{{ $tab['url'] }}" class="{{ $tab['key'] === $active_tab ? 'is-active' : '' }}">{{ $tab['label'] }}</a>
        @endforeach
    </nav>

    <main class="dz-stack">
        {!! $content !!}
    </main>

</div>
</body>
</html>
