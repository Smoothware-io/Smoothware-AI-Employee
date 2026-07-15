<?php

namespace App\Enums;

enum CallDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
