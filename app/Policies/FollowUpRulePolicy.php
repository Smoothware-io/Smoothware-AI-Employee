<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FollowUpRule;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class FollowUpRulePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:FollowUpRule');
    }

    public function view(AuthUser $authUser, FollowUpRule $followUpRule): bool
    {
        return $authUser->can('View:FollowUpRule');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:FollowUpRule');
    }

    public function update(AuthUser $authUser, FollowUpRule $followUpRule): bool
    {
        return $authUser->can('Update:FollowUpRule');
    }

    public function delete(AuthUser $authUser, FollowUpRule $followUpRule): bool
    {
        return $authUser->can('Delete:FollowUpRule');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:FollowUpRule');
    }

    public function restore(AuthUser $authUser, FollowUpRule $followUpRule): bool
    {
        return $authUser->can('Restore:FollowUpRule');
    }

    public function forceDelete(AuthUser $authUser, FollowUpRule $followUpRule): bool
    {
        return $authUser->can('ForceDelete:FollowUpRule');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:FollowUpRule');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:FollowUpRule');
    }

    public function replicate(AuthUser $authUser, FollowUpRule $followUpRule): bool
    {
        return $authUser->can('Replicate:FollowUpRule');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:FollowUpRule');
    }
}
