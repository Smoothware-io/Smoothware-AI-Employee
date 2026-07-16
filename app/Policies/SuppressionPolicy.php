<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Suppression;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SuppressionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Suppression');
    }

    public function view(AuthUser $authUser, Suppression $suppression): bool
    {
        return $authUser->can('View:Suppression');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Suppression');
    }

    public function update(AuthUser $authUser, Suppression $suppression): bool
    {
        return $authUser->can('Update:Suppression');
    }

    public function delete(AuthUser $authUser, Suppression $suppression): bool
    {
        return $authUser->can('Delete:Suppression');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Suppression');
    }

    public function restore(AuthUser $authUser, Suppression $suppression): bool
    {
        return $authUser->can('Restore:Suppression');
    }

    public function forceDelete(AuthUser $authUser, Suppression $suppression): bool
    {
        return $authUser->can('ForceDelete:Suppression');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Suppression');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Suppression');
    }

    public function replicate(AuthUser $authUser, Suppression $suppression): bool
    {
        return $authUser->can('Replicate:Suppression');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Suppression');
    }
}
