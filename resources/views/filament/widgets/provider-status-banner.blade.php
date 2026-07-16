<x-filament-widgets::widget>
    <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-700 dark:bg-warning-900/20">
        <div class="flex items-start gap-3">
            <x-filament::icon
                icon="heroicon-o-beaker"
                class="mt-0.5 h-5 w-5 flex-shrink-0 text-warning-600 dark:text-warning-400"
            />

            <div class="text-sm">
                <p class="font-semibold text-warning-800 dark:text-warning-200">
                    @if ($this->getIsAllFake())
                        Demo data — no AI providers are connected yet.
                    @else
                        Partial demo data — some AI providers are not connected yet.
                    @endif
                </p>

                <p class="mt-1 text-warning-700 dark:text-warning-300">
                    The AI metrics below are computed from
                    <strong>offline fakes</strong>, so they describe our test stubs rather than
                    real calls, real analyses or real prospects.
                    <strong>Do not read them as KPIs</strong> — this dashboard is the instrument,
                    and the engine isn't running yet.
                </p>

                <p class="mt-2 text-warning-700 dark:text-warning-300">
                    Still faked:
                    @foreach ($this->getFakes() as $fake)
                        <span class="mx-0.5 inline-flex items-center rounded-md bg-warning-100 px-1.5 py-0.5 font-medium text-warning-800 dark:bg-warning-900/40 dark:text-warning-200">{{ $fake }}</span>
                    @endforeach
                </p>

                <p class="mt-2 text-xs text-warning-600 dark:text-warning-400">
                    This notice reads the live driver config and will clear itself as each real
                    provider is wired.
                </p>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
