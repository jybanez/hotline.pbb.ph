@extends('layouts.base', [
    'vite' => ['resources/css/shared.css', 'resources/css/public.css'],
])

@section('title', $sitrep->title.' | PBB Hotline SITREP')
@section('body_class', 'sitrep-body'.(($isPdf ?? false) ? ' sitrep-pdf-body' : ''))

@section('body')
<main class="sitrep-page @if ($isPreview) is-preview @endif @if ($isPdf ?? false) is-pdf @endif @if ($sitrep->status === 'draft') is-draft @endif">
    @include('pages.sitrep.partials.document', [
        'sitrep' => $sitrep,
        'isPreview' => $isPreview,
    ])

    @if (($archiveSitreps ?? collect())->isNotEmpty())
        <section class="sitrep-home-archive" aria-label="Recent public SITREPs">
            <div class="sitrep-section-head">
                <p class="sitrep-eyebrow">Archive</p>
                <h2>Recent Public SITREPs</h2>
            </div>
            <div class="sitrep-home-archive-list">
                @foreach ($archiveSitreps as $archiveSitrep)
                    <a class="sitrep-home-archive-row" href="{{ route('sitrep.public.show', ['sitrep' => $archiveSitrep]) }}">
                        <span>
                            <strong>{{ $archiveSitrep->title }}</strong>
                            <small>#{{ str_pad((string) $archiveSitrep->sequence_number, 4, '0', STR_PAD_LEFT) }} · {{ $archiveSitrep->generated_at?->format('M d, Y g:i A') }}</small>
                        </span>
                        <span>{{ $archiveSitrep->alert_level ?? 'Normal' }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</main>
@endsection
