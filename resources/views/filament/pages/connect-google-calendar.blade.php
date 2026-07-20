@php
    $account = $this->getAccount();
    $configured = $this->isConfigured();
@endphp

<x-filament-panels::page>
    @unless ($configured)
        <x-filament::section>
            <x-slot name="heading">Not configured on this server</x-slot>

            <p class="text-sm">
                Google Calendar credentials are not set. Until an administrator adds
                <code>GOOGLE_CLIENT_ID</code>, <code>GOOGLE_CLIENT_SECRET</code> and
                <code>GOOGLE_REDIRECT_URI</code>, the AI books using only the working hours
                and blocked time configured in this app.
            </p>
        </x-filament::section>
    @elseif ($account)
        <x-filament::section>
            <x-slot name="heading">Connected</x-slot>

            <div class="text-sm space-y-2">
                <p><strong>Account:</strong> {{ $account->google_email ?? 'unknown' }}</p>
                <p>
                    <strong>Blocking from your calendar:</strong>
                    {{ $account->block_from_busy ? 'yes — the AI avoids times you are busy' : 'no' }}
                </p>
                <p>
                    <strong>Adding meetings to your calendar:</strong>
                    {{ $account->push_appointments ? 'yes' : 'no' }}
                </p>
                @if ($account->last_synced_at)
                    <p><strong>Last read:</strong> {{ $account->last_synced_at->diffForHumans() }}</p>
                @endif
            </div>

            @if ($account->last_error)
                {{-- A dead connection looks identical to an empty calendar from the
                     outside, so it has to be said out loud rather than inferred. --}}
                <div class="mt-4 text-sm text-danger-600 dark:text-danger-400">
                    <strong>Problem:</strong> {{ $account->last_error }}
                </div>
            @endif
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Not connected</x-slot>

            <p class="text-sm">
                Connect your Google Calendar and the AI will never offer a caller a time
                when you already have a meeting. Anything it books is added to your
                calendar automatically.
            </p>

            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                We read only when you are busy — never the titles, guests or contents of
                your events.
            </p>
        </x-filament::section>
    @endunless
</x-filament-panels::page>
