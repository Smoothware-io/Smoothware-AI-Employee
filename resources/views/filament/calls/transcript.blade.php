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

    $callerLabel = $record->from_number ?: 'Caller';
@endphp

<style>
    .sw-convo {
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
        width: 100%;
        padding: .25rem 0;
    }

    .sw-turn { display: flex; gap: .75rem; align-items: flex-start; width: 100%; }
    /* The AI is the "self" side here: this is our system's output, reviewed by us. */
    .sw-turn--ai { flex-direction: row-reverse; }

    .sw-avatar {
        flex: 0 0 auto;
        width: 2rem; height: 2rem;
        border-radius: 9999px;
        display: flex; align-items: center; justify-content: center;
        font-size: .6875rem; font-weight: 700; letter-spacing: .02em;
        margin-top: 1.35rem; /* aligns with the bubble body, not the name label */
    }
    .sw-turn--caller .sw-avatar { background: #e4e4e7; color: #3f3f46; }
    .sw-turn--ai .sw-avatar { background: #2563eb; color: #fff; }
    .sw-turn--system .sw-avatar { background: transparent; }

    .sw-stack { display: flex; flex-direction: column; min-width: 0; max-width: 80%; }
    .sw-turn--ai .sw-stack { align-items: flex-end; }

    .sw-who {
        font-size: .6875rem; font-weight: 600;
        letter-spacing: .06em; text-transform: uppercase;
        color: #71717a; margin: 0 .25rem .3rem;
    }

    .sw-bubble {
        padding: .75rem 1rem;
        border-radius: 1rem;
        font-size: .9375rem; line-height: 1.6;
        white-space: pre-wrap; overflow-wrap: anywhere;
        border: 1px solid transparent;
    }
    .sw-turn--caller .sw-bubble {
        background: #fafafa; color: #18181b; border-color: #e4e4e7;
        border-bottom-left-radius: .25rem;
    }
    .sw-turn--ai .sw-bubble {
        background: #2563eb; color: #fff;
        border-bottom-right-radius: .25rem;
    }

    /* Unlabelled preamble — present, but never dressed as speech. */
    .sw-turn--system { justify-content: center; }
    .sw-turn--system .sw-stack { max-width: 100%; align-items: center; }
    .sw-turn--system .sw-who { display: none; }
    .sw-turn--system .sw-bubble {
        background: transparent; border: 0; color: #a1a1aa;
        font-size: .8125rem; font-style: italic; padding: .25rem 0;
    }

    .sw-empty { font-size: .875rem; color: #71717a; padding: .5rem 0; }

    @media (max-width: 48rem) {
        .sw-stack { max-width: 100%; }
    }

    .dark .sw-turn--caller .sw-avatar { background: #3f3f46; color: #e4e4e7; }
    .dark .sw-turn--caller .sw-bubble { background: #27272a; color: #e4e4e7; border-color: #3f3f46; }
    .dark .sw-turn--ai .sw-bubble { background: #1d4ed8; color: #fff; }
    .dark .sw-who { color: #a1a1aa; }
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
                <div class="sw-avatar">{{ $turn['speaker'] === 'ai' ? 'AI' : ($turn['speaker'] === 'caller' ? '👤' : '') }}</div>
                <div class="sw-stack">
                    <span class="sw-who">{{ $turn['speaker'] === 'ai' ? 'AI' : ($turn['speaker'] === 'caller' ? $callerLabel : 'System') }}</span>
                    <div class="sw-bubble">{{ $turn['text'] }}</div>
                </div>
            </div>
        @endforeach
    </div>
@endif
