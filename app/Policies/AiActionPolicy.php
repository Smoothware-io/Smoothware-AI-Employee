<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AiAction;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AiActionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AiAction');
    }

    public function view(AuthUser $authUser, AiAction $aiAction): bool
    {
        return $authUser->can('View:AiAction');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AiAction');
    }

    public function update(AuthUser $authUser, AiAction $aiAction): bool
    {
        return $authUser->can('Update:AiAction');
    }

    public function delete(AuthUser $authUser, AiAction $aiAction): bool
    {
        return $authUser->can('Delete:AiAction');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:AiAction');
    }

    public function restore(AuthUser $authUser, AiAction $aiAction): bool
    {
        return $authUser->can('Restore:AiAction');
    }

    public function forceDelete(AuthUser $authUser, AiAction $aiAction): bool
    {
        return $authUser->can('ForceDelete:AiAction');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AiAction');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AiAction');
    }

    public function replicate(AuthUser $authUser, AiAction $aiAction): bool
    {
        return $authUser->can('Replicate:AiAction');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AiAction');
    }
}
