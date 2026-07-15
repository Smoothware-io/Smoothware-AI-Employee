<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\KnowledgeEntry;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class KnowledgeEntryPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:KnowledgeEntry');
    }

    public function view(AuthUser $authUser, KnowledgeEntry $knowledgeEntry): bool
    {
        return $authUser->can('View:KnowledgeEntry');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:KnowledgeEntry');
    }

    public function update(AuthUser $authUser, KnowledgeEntry $knowledgeEntry): bool
    {
        return $authUser->can('Update:KnowledgeEntry');
    }

    public function delete(AuthUser $authUser, KnowledgeEntry $knowledgeEntry): bool
    {
        return $authUser->can('Delete:KnowledgeEntry');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:KnowledgeEntry');
    }

    public function restore(AuthUser $authUser, KnowledgeEntry $knowledgeEntry): bool
    {
        return $authUser->can('Restore:KnowledgeEntry');
    }

    public function forceDelete(AuthUser $authUser, KnowledgeEntry $knowledgeEntry): bool
    {
        return $authUser->can('ForceDelete:KnowledgeEntry');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:KnowledgeEntry');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:KnowledgeEntry');
    }

    public function replicate(AuthUser $authUser, KnowledgeEntry $knowledgeEntry): bool
    {
        return $authUser->can('Replicate:KnowledgeEntry');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:KnowledgeEntry');
    }
}
