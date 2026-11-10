{{-- The front page, then the live entries of each type with an address. --}}
<p class="text-sm">
    <span class="text-muted">Front page</span>
    @if ($front === null)
        <span class="block font-medium">No front page chosen</span>
    @elseif ($front['href'] !== null)
        <a href="{{ $front['href'] }}" class="block truncate font-medium text-accent hover:underline">{{ $front['title'] }}</a>
    @else
        <span class="block truncate font-medium">{{ $front['title'] ?? 'A front page is chosen' }}</span>
    @endif
</p>
<ul class="mt-2 space-y-2 text-sm">
    @foreach ($types as $type)
        <li class="flex items-baseline justify-between gap-2">
            <a href="{{ $type['href'] }}" class="min-w-0 truncate text-muted hover:underline">{{ $type['label'] }}</a>
            <span class="tabular-nums">{{ $type['count'] }}</span>
        </li>
    @endforeach
</ul>
