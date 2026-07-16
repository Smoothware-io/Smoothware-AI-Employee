{{--
    Built from Filament's OWN components (fi-section, fi-badge) rather than raw
    Tailwind utilities.

    This panel has no custom theme (no ->viteTheme(), no npm build), so the only
    CSS shipped is Filament's own compiled stylesheet — which contains its
    component classes but NOT general utilities. Verified: `.flex`, `.p-4`,
    `.rounded-xl`, `.text-sm` and `bg-warning-50` are all absent from
    public/css/filament/filament/app.css. A hand-rolled Tailwind box here renders
    as unstyled stacked text.
--}}
<x-filament-widgets::widget>
    <x-filament::section
        icon="heroicon-o-beaker"
        icon-color="warning"
    >
        <x-slot name="heading">
            @if ($this->getIsAllFake())
                Demo data — no AI providers are connected yet
            @else
                Partial demo data — some AI providers are not connected yet
            @endif
        </x-slot>

        <x-slot name="description">
            The AI metrics below describe our offline test stubs, not real calls,
            analyses or prospects. Do not read them as KPIs.
        </x-slot>

        <div>
            @foreach ($this->getFakes() as $fake)
                <x-filament::badge color="warning" class="fi-inline">
                    {{ $fake }}
                </x-filament::badge>
            @endforeach
        </div>

        <p>
            This notice reads the live driver config and clears itself as each real
            provider is wired.
        </p>
    </x-filament::section>
</x-filament-widgets::widget>
