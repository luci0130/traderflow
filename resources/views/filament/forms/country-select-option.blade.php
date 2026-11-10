<span class="inline-flex max-w-full items-center gap-2">
    <img src="{{ \App\Support\Countries::flagUrl($code) }}" alt="{{ $code }}" class="h-3 w-5 shrink-0 rounded-xs object-cover" />
    @if ($callingCode)
        <span class="shrink-0">+{{ $callingCode }}</span>
    @endif
    <span class="truncate">{{ $label }} ({{ $code }})</span>
</span>
