<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PromptRuleSet;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PromptRuleSetPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PromptRuleSet');
    }

    public function view(AuthUser $authUser, PromptRuleSet $promptRuleSet): bool
    {
        return $authUser->can('View:PromptRuleSet');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PromptRuleSet');
    }

    public function update(AuthUser $authUser, PromptRuleSet $promptRuleSet): bool
    {
        return $authUser->can('Update:PromptRuleSet');
    }

    public function delete(AuthUser $authUser, PromptRuleSet $promptRuleSet): bool
    {
        return $authUser->can('Delete:PromptRuleSet');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PromptRuleSet');
    }

    public function restore(AuthUser $authUser, PromptRuleSet $promptRuleSet): bool
    {
        return $authUser->can('Restore:PromptRuleSet');
    }

    public function forceDelete(AuthUser $authUser, PromptRuleSet $promptRuleSet): bool
    {
        return $authUser->can('ForceDelete:PromptRuleSet');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PromptRuleSet');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PromptRuleSet');
    }

    public function replicate(AuthUser $authUser, PromptRuleSet $promptRuleSet): bool
    {
        return $authUser->can('Replicate:PromptRuleSet');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PromptRuleSet');
    }
}
