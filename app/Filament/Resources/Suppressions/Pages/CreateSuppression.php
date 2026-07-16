<?php

namespace App\Filament\Resources\Suppressions\Pages;

use App\Enums\SuppressionSource;
use App\Enums\SuppressionType;
use App\Filament\Resources\Suppressions\SuppressionResource;
use App\Services\SuppressionList;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateSuppression extends CreateRecord
{
    protected static string $resource = SuppressionResource::class;

    /**
     * Created through {@see SuppressionList} rather than by mass-assignment, so
     * the value is NORMALISED by the same code path that will later match it.
     * A form that wrote value_normalized straight from the input would produce
     * entries that never match anything — the silent failure this list exists to
     * prevent.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $type = SuppressionType::from($data['type']);

        $record = app(SuppressionList::class)->suppress(
            type: $type,
            value: $data['value_raw'],
            source: SuppressionSource::from($data['source'] ?? SuppressionSource::Manual->value),
            reason: $data['reason'] ?? null,
            userId: Auth::id(),
        );

        if ($record === null) {
            // Better a clear error than a row that silently matches nothing.
            throw ValidationException::withMessages([
                'data.value_raw' => "That doesn't look like a usable {$type->value}. Nothing was saved — please check it.",
            ]);
        }

        if (! $record->wasRecentlyCreated) {
            Notification::make()
                ->title('Already suppressed')
                ->body('That address was already on the list — nothing changed.')
                ->info()
                ->send();
        }

        return $record;
    }
}
