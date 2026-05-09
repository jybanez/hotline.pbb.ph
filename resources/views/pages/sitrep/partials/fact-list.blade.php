@if (count($items) > 0)
    <div class="sitrep-fact-list">
        @foreach ($items as $item)
            <article class="sitrep-fact">
                <span>#{{ str_pad((string) ($item['incident_id'] ?? ''), 6, '0', STR_PAD_LEFT) }}</span>
                <strong>{{ $item['label'] ?? 'Reported fact' }}</strong>
                <p>{{ $item['value'] ?? '' }}{{ isset($item['unit']) && $item['unit'] ? ' '.$item['unit'] : '' }}</p>
            </article>
        @endforeach
    </div>
@else
    <p class="sitrep-empty">{{ $empty }}</p>
@endif
