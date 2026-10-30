<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Opsbot\Access\ActorFactory;
use App\Domain\Opsbot\DocumentVisibility;
use App\Enums\ApprovalStatus;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;

final readonly class DocumentPolicy
{
    public function __construct(
        private ActorFactory $actors,
        private DocumentVisibility $visibility,
        private CurrentWorkspace $current,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can('knowledge.view');
    }

    public function view(User $user, Document $document): bool
    {
        return $user->can('knowledge.view') && $this->visible($user, $document);
    }

    public function create(User $user): bool
    {
        return $user->can('knowledge.manage');
    }

    public function update(User $user, Document $document): bool
    {
        if (! $this->visible($user, $document)) {
            return false;
        }

        return match ($document->approval_status) {
            ApprovalStatus::Draft => $user->can('knowledge.manage'),
            ApprovalStatus::Approved => $user->can('knowledge.approve'),
            ApprovalStatus::Archived => false,
        };
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->visible($user, $document)
            && $user->can('knowledge.approve')
            && $document->approval_status !== ApprovalStatus::Approved;
    }

    private function visible(User $user, Document $document): bool
    {
        if ($this->current->id() !== $document->workspace_id) {
            return false;
        }

        $workspace = Workspace::query()->find($document->workspace_id);
        $actor = $workspace !== null ? $this->actors->forUser($user, $workspace) : null;

        return $actor !== null && $this->visibility->visibleTo($document, $actor);
    }
}
