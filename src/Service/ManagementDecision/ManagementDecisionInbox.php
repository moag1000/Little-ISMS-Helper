<?php

declare(strict_types=1);

namespace App\Service\ManagementDecision;

use App\Entity\User;
use App\Repository\ManagementDecisionRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * My Day category "Leitungsentscheidungen": proposals waiting for top
 * management, decided right there with the decision dialog.
 */
final readonly class ManagementDecisionInbox
{
    public function __construct(
        private ManagementDecisionRepository $repository,
        private Security $security,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function pendingFor(User $user): array
    {
        $tenant = $user->getTenant();
        if ($tenant === null || !$this->security->isGranted('ROLE_TOP_MGMT')) {
            return [];
        }
        $items = [];
        foreach ($this->repository->findPending($tenant) as $decision) {
            $items[] = [
                'priority' => $decision->isOverdue() ? 'high' : 'medium',
                'due_date' => $decision->getDueDate(),
                'link' => $this->urls->generate('app_management_decision_show', ['id' => $decision->getId()]),
                'entity_type' => 'management_decision',
                'tone' => $decision->isOverdue() ? 'danger' : 'warning',
                'title' => (string) $decision->getTitle(),
                'subtitle' => $this->translator->trans($decision->getType()->label(), [], 'management_decision')
                    . ' · ' . $decision->getType()->normReference(),
                'badge' => null,
                'management_decision_id' => $decision->getId(),
            ];
        }

        return $items;
    }
}
