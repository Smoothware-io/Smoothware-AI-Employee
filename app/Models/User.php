<?php

namespace App\Models;

use App\Concerns\LogsEvents;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsEvents, Notifiable, SoftDeletes;

    /**
     * Smoothware convention: nothing is hard-deleted except for GDPR erasure,
     * so soft deletes are stored in `archived_at` (not Laravel's `deleted_at`).
     */
    const DELETED_AT = 'archived_at';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Panel access gate. Only active users holding at least one role may enter
     * the admin panel; per-resource authorization is enforced by Shield
     * policies on top of this. Archived users are already excluded by the
     * SoftDeletes global scope, so they can never authenticate here.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->roles()->exists();
    }
}
