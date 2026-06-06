<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentRepository;

/**
 * Tier-2 hint: Approved documents without any policy acknowledgement record.
 *
 * ISO 27001 A.6.3 / Cl. 7.3 awareness requires demonstrable employee
 * acknowledgement of published mandatory policies. Zero-acknowledgement
 * is an audit gap on every published policy.
 */
final class ApprovedDocOhneAcknowledgementRule extends AbstractGlobalAlvaHintRule
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
    ) {
    }

    public function key(): string
    {
        return 'global.approved_doc_ohne_ack';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return [];
    }

    public function appliesToPages(): array
    {
        return [
            'document_index',
            'dashboard_ciso',
            'dashboard_compliance_manager',
        ];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        // Single source of truth shared with the index `focus=no_ack` filter,
        // so the hint deep-links to EXACTLY the documents it counts.
        $unacked = $this->documentRepository->findApprovedWithoutAcknowledgement($tenant);
        $count = count($unacked);

        if ($count <= 0) {
            return null;
        }

        // Deep-link to exactly what the hint counts: one → that document,
        // several → the document index pre-filtered to the same set.
        if ($count === 1) {
            $route = 'app_document_show';
            $params = ['id' => $unacked[0]->getId() ?? 0];
        } else {
            $route = 'app_document_index';
            $params = ['focus' => 'no_ack'];
        }

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'global.approved_doc_ohne_ack.title',
            bodyTranslationKey: 'global.approved_doc_ohne_ack.body',
            bodyTranslationParams: ['%count%' => (string) $count],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Tenant',
            entityId: $tenant->getId() ?? 0,
            actionLabelTranslationKey: 'global.approved_doc_ohne_ack.action',
            actionRoute: $route,
            actionRouteParams: $params,
            actionMethod: 'GET',
            requiredRoles: ['ROLE_USER'],
            mood: 'thinking',
        );
    }
}
