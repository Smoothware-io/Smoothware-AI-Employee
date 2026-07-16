{{-- Built from Filament's own components — this panel has no custom theme, so
     raw Tailwind utilities do not exist. See ARCHITECTURE §11. --}}
<x-filament-panels::page>
    @php($account = $this->getAccount())

    @if ($account === null)
        <x-filament::section icon="heroicon-o-phone-x-mark" icon-color="gray">
            <x-slot name="heading">Not connected</x-slot>
            <x-slot name="description">
                The AI cannot place calls as you until your Sonetel account is linked.
            </x-slot>

            <p>
                Connect your own Sonetel account so calls are placed from your number and
                billed to you. Your password is used once to obtain a token and is never
                stored by this app.
            </p>
        </x-filament::section>
    @elseif ($account->hasFreshToken())
        <x-filament::section icon="heroicon-o-check-badge" icon-color="success">
            <x-slot name="heading">Sonetel connected</x-slot>
            <x-slot name="description">{{ $account->username }}</x-slot>

            <div>
                <p>
                    Caller ID:
                    <x-filament::badge color="info">
                        {{ $account->sonetel_number ?: 'automatic (Sonetel picks)' }}
                    </x-filament::badge>
                </p>

                <p>
                    Token valid until {{ $account->expires_at?->format('d M Y, H:i') }}
                    ({{ $account->expires_at?->diffForHumans() }}) — it refreshes itself, so
                    you should not need to do this again.
                </p>

                @if ($account->last_refreshed_at)
                    <p>Last refreshed {{ $account->last_refreshed_at->diffForHumans() }}.</p>
                @endif
            </div>
        </x-filament::section>
    @else
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
            <x-slot name="heading">Reconnect needed</x-slot>
            <x-slot name="description">{{ $account->username }}</x-slot>

            <p>
                The token expired and could not be refreshed automatically — that usually
                means the refresh token was revoked, or the Sonetel password changed.
                Calls will be refused until you reconnect.
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
