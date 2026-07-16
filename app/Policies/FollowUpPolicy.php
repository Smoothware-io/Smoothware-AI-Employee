<?php

declare(strict_types=1);

namespace App\Policies;

use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Models\FollowUp;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Matches Filament Shield's generated shape exactly. The follow-up ledger is
 * read-only in the UI ({@see FollowUpResource::canCreate()});
 * nobody is granted the write permissions below.
 */
class FollowUpPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:FollowUp');
    }

    public function view(AuthUser $authUser, FollowUp $followUp): bool
    {
        return $authUser->can('View:FollowUp');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:FollowUp');
    }

    public function update(AuthUser $authUser, FollowUp $followUp): bool
    {
        return $authUser->can('Update:FollowUp');
    }

    public function delete(AuthUser $authUser, FollowUp $followUp): bool
    {
        return $authUser->can('Delete:FollowUp');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:FollowUp');
    }

    public function restore(AuthUser $authUser, FollowUp $followUp): bool
    {
        return $authUser->can('Restore:FollowUp');
    }

    public function forceDelete(AuthUser $authUser, FollowUp $followUp): bool
    {
        return $authUser->can('ForceDelete:FollowUp');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:FollowUp');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:FollowUp');
    }

    public function replicate(AuthUser $authUser, FollowUp $followUp): bool
    {
        return $authUser->can('Replicate:FollowUp');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:FollowUp');
    }
}
