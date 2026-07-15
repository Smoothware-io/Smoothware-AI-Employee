<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CompanyStatus: string implements HasColor, HasLabel
{
    case Lead = 'lead';
    case Qualified = 'qualified';
    case Customer = 'customer';
    case Dormant = 'dormant';
    case Disqualified = 'disqualified';

    public function getLabel(): string
    {
        return match ($this) {
            self::Lead => 'Lead',
            self::Qualified => 'Qualified',
            self::Customer => 'Customer',
            self::Dormant => 'Dormant',
            self::Disqualified => 'Disqualified',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Lead => 'gray',
            self::Qualified => 'info',
            self::Customer => 'success',
            self::Dormant => 'warning',
            self::Disqualified => 'danger',
        };
    }
}
