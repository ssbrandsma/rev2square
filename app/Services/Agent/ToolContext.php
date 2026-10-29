<?php

namespace App\Services\Agent;

use App\Models\ArchitectureSnapshot;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;

/**
 * Authorization scope for one agent tool invocation.
 *
 * Built by the controller after policy checks; every tool re-validates
 * it, because a model-supplied id or path is never authorization.
 * Artifact tools additionally require the conversation's explicit
 * consent timestamp plus a server-side artifact allowlist.
 */
final class ToolContext
{
    /**
     * @param  list<int>  $artifactIds  Server-validated usable artifact ids for this conversation.
     */
    public function __construct(
        public readonly User $user,
        public readonly Project $project,
        public readonly ?Repository $repository,
        public readonly ?ArchitectureSnapshot $snapshot = null,
        public readonly array $artifactIds = [],
        public readonly bool $artifactConsent = false,
    ) {}

    public function allowsArtifacts(): bool
    {
        return $this->artifactConsent && $this->artifactIds !== [];
    }
}
