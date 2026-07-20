<?php

namespace App\Filament\Resources\AvailabilityRules\Pages;

use App\Filament\Resources\AvailabilityRules\AvailabilityRuleResource;
use Filament\Resources\Pages\ManageRecords;

/**
 * ManageRecords, not List+Create+Edit: a working-hours rule is four fields, and
 * bouncing between three pages to say "Mondays nine to five" is friction for no
 * gain. Everything happens in a modal on one page.
 */
class ManageAvailabilityRules extends ManageRecords
{
    protected static string $resource = AvailabilityRuleResource::class;
}
