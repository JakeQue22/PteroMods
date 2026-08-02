<section class="dz-card">
    <h2>Configuration</h2>
    <p class="dz-sub">Browse DayZ configuration files with structured categories and editor capabilities.</p>
</section>

@foreach ($files as $category => $entries)
    <section class="dz-card">
        <h2>{{ $category }}</h2>
        <ul class="dz-list">
            @forelse ($entries as $entry)
                <li><span>{{ $entry }}</span></li>
            @empty
                <li class="dz-empty">No files matched this category.</li>
            @endforelse
        </ul>
    </section>
@endforeach

<section class="dz-card">
    <h2>Editor Capabilities</h2>
    <ul class="dz-tags">
        @foreach ($editor_features as $feature)
            <li>{{ $feature }}</li>
        @endforeach
    </ul>
</section>
