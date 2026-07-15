<?php

namespace App\Enums;

enum CompanyStatus: string
{
    case Lead = 'lead';
    case Qualified = 'qualified';
    case Customer = 'customer';
    case Dormant = 'dormant';
    case Disqualified = 'disqualified';

    public function label(): string
    {
        return match ($this) {
            self::Lead => 'Lead',
            self::Qualified => 'Qualified',
            self::Customer => 'Customer',
            self::Dormant => 'Dormant',
            self::Disqualified => 'Disqualified',
        };
    }

    public function color(): string
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
