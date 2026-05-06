<?php

declare(strict_types=1);

namespace App\AlvaHint;

use App\Entity\AlvaHintDismissal;
use App\Entity\User;
use App\Repository\AlvaHintDismissalRepository;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Aggregates AlvaHintRule services and selects at most one hint per call.
 *
 * Rules are discovered via the `alva.hint_rule` service tag and ordered
 * by priority tier (regulatory > audit gap > efficiency). Within the
 * same tier, the registration order wins. Dismissal records are checked
 * in bulk per request so individual rules never need to know about
 * persistence.
 */
class AlvaHintService
{
    /**
     * @param iterable<AlvaHintRuleInterface> $rules
     */
    public function __construct(
        private readonly Security $security,
        private readonly AlvaHintDismissalRepository $dismissalRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ModuleConfigurationService $moduleConfigurationService,
        private readonly iterable $rules = [],
    ) {
    }

    /**
     * Pick the highest-priority undismissed hint for the given entity, or
     * null if no rule applies. The entity may be any object — rules
     * check type via instanceof internally.
     */
    public function pickHintFor(object $entity): ?AlvaHint
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $activeModules = $this->moduleConfigurationService->getActiveModules();

        $candidates = [];
        foreach ($this->rules as $rule) {
            $required = $rule->requiredModules();
            if ($required !== [] && array_diff($required, $activeModules) !== []) {
                // Tenant has not enabled at least one module the hint
                // would route into — silently skip.
                continue;
            }
            if (!$rule->appliesTo($entity, $user)) {
                continue;
            }
            $candidates[] = [$rule->priorityTier(), $rule];
        }

        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            static fn(array $a, array $b): int => $a[0] <=> $b[0],
        );

        $dismissed = $this->dismissalRepository->findDismissedTokensForUser($user);
        $dismissedSet = array_flip($dismissed);

        foreach ($candidates as [$tier, $rule]) {
            $hint = $rule->build($entity, $user);
            $token = sprintf('%s|%s|%d', $hint->key, $hint->entityType, $hint->entityId);
            if ($hint->dismissible && isset($dismissedSet[$token])) {
                continue;
            }
            return $hint;
        }

        return null;
    }

    public function dismiss(User $user, string $hintKey, string $entityType, int $entityId): void
    {
        $existing = $this->dismissalRepository->findOneFor($user, $hintKey, $entityType, $entityId);
        if ($existing instanceof AlvaHintDismissal) {
            return;
        }

        $dismissal = (new AlvaHintDismissal())
            ->setUser($user)
            ->setHintKey($hintKey)
            ->setEntityType($entityType)
            ->setEntityId($entityId);

        $this->entityManager->persist($dismissal);
        $this->entityManager->flush();
    }
}
