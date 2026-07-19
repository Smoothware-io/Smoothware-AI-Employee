{{--
    The call, rendered as a conversation.

    Styling is a scoped <style> block rather than Tailwind utilities on purpose:
    Filament compiles its own stylesheet and does not scan this file, so utility
    classes used only here would be purged and the view would render unstyled in
    production while looking fine locally.
--}}
@php
    // Filament injects the component's PUBLIC METHODS into the view as closures,
    // not the record itself. `$record` is undefined here and referencing it 500s
    // the whole page — which is exactly how this shipped the first time.
    /** @var \App\Models\Call $record */
    $record = $getRecord();
    $turns = \App\Support\TranscriptParser::parse($record->transcript);

    // Built here rather than with an inline @if: a directive glued to the end of
    // a word ("yet@if") does not match Blade's directive boundary, so it survives
    // as literal text while its @endif compiles — which desyncs every branch
    // after it and takes the page down with a stray `else`.
    $emptyMessage = filled($record->transcript_status)
        ? "No transcript yet — status: {$record->transcript_status}."
        : 'No transcript yet.';
@endphp

<style>
    .sw-convo { display: flex; flex-direction: column; gap: .75rem; }
    .sw-turn { display: flex; }
    .sw-turn--caller { justify-content: flex-start; }
    .sw-turn--ai, .sw-turn--system { justify-content: flex-end; }
    .sw-bubble {
        max-width: 46rem; padding: .625rem .875rem; border-radius: .75rem;
        font-size: .875rem; line-height: 1.5; white-space: pre-wrap;
        overflow-wrap: anywhere; border: 1px solid transparent;
    }
    .sw-who { display: block; font-size: .6875rem; font-weight: 600;
        letter-spacing: .04em; text-transform: uppercase; margin-bottom: .25rem; opacity: .7; }
    .sw-turn--caller .sw-bubble { background: #f4f4f5; color: #18181b; border-color: #e4e4e7; }
    .sw-turn--ai .sw-bubble { background: #eff6ff; color: #1e3a8a; border-color: #bfdbfe; }
    .sw-turn--system .sw-bubble { background: transparent; color: #71717a; font-style: italic; }
    .sw-empty { font-size: .875rem; color: #71717a; }
    .dark .sw-turn--caller .sw-bubble { background: #27272a; color: #e4e4e7; border-color: #3f3f46; }
    .dark .sw-turn--ai .sw-bubble { background: #1e293b; color: #bfdbfe; border-color: #1e40af; }
    .dark .sw-turn--system .sw-bubble { color: #a1a1aa; }
    .dark .sw-empty { color: #a1a1aa; }
</style>

@if ($record->isContentErased())
    {{-- Erased is not the same as never-recorded, and a reviewer must be able to
         tell the difference: one is a GDPR action, the other is a broken pipeline. --}}
    <p class="sw-empty">Call content was erased on {{ $record->content_erased_at?->format('d M Y, H:i') }} (retention or GDPR request). Metadata is kept; the conversation is gone.</p>
@elseif ($turns === [])
    <p class="sw-empty">{{ $emptyMessage }}</p>
@else
    <div class="sw-convo">
        @foreach ($turns as $turn)
            <div class="sw-turn sw-turn--{{ $turn['speaker'] }}">
                <div class="sw-bubble">
                    <span class="sw-who">{{ $turn['speaker'] === 'ai' ? 'AI' : ucfirst($turn['speaker']) }}</span>
                    {{ $turn['text'] }}
                </div>
            </div>
        @endforeach
    </div>
@endif
