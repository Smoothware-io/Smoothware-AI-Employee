<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** The AI's detected reason for an inbound call. */
enum CallIntent: string implements HasColor, HasLabel
{
    case SalesInquiry = 'sales_inquiry';
    case ExistingCustomer = 'existing_customer';
    case Support = 'support';
    case Spam = 'spam';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::SalesInquiry => 'Sales inquiry',
            self::ExistingCustomer => 'Existing customer',
            self::Support => 'Support',
            self::Spam => 'Spam',
            self::Other => 'Other',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SalesInquiry => 'success',
            self::ExistingCustomer => 'info',
            self::Support => 'warning',
            self::Spam => 'danger',
            self::Other => 'gray',
        };
    }
}
