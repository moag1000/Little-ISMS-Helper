<?php

declare(strict_types=1);

namespace App\Lifecycle;

/**
 * S28 (F-09): a record whose kind decides who may take a step — a policy document
 * is approved and published by the CISO or top management (ISO 27001 5.2), any other
 * document by the YAML roles of `document_lifecycle`.
 *
 * {@see \App\Lifecycle\Config\LifecycleConfigResolver::resolve()} puts these roles in
 * place of the configured ones (YAML and organisation overrides), so the voter, the
 * four-eyes check, the facade and every view read the same answer.
 */
interface RecordTransitionRoles
{
    /** @return list<string>|null the roles that decide this step for this record; null = configured roles */
    public function transitionRoles(string $workflowName, string $transitionName): ?array;
}
