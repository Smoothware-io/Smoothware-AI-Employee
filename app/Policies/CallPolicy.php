<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Call;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CallPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Call');
    }

    public function view(AuthUser $authUser, Call $call): bool
    {
        return $authUser->can('View:Call');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Call');
    }

    public function update(AuthUser $authUser, Call $call): bool
    {
        return $authUser->can('Update:Call');
    }

    public function delete(AuthUser $authUser, Call $call): bool
    {
        return $authUser->can('Delete:Call');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Call');
    }

    public function restore(AuthUser $authUser, Call $call): bool
    {
        return $authUser->can('Restore:Call');
    }

    public function forceDelete(AuthUser $authUser, Call $call): bool
    {
        return $authUser->can('ForceDelete:Call');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Call');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Call');
    }

    public function replicate(AuthUser $authUser, Call $call): bool
    {
        return $authUser->can('Replicate:Call');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Call');
    }
}
