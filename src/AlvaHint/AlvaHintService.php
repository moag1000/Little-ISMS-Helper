<?php

declare(strict_types=1);

namespace App\AlvaHint;

use App\Entity\AlvaHintDismissal;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AlvaHintDismissalRepository;
use App\Repository\AlvaHintRenderCountRepository;
use App\Service\ModuleConfigurationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Aggregates AlvaHintRule services and selects at most one hint per call.
 *
 * Rules are discovered via the `alva.hint_rule` service tag and ordered
 * by priority tier (regulatory > audit gap > efficiency). Within the
 * same tier, registration order wins. Per request:
 * - active modules are read once
 * - per-(user,tenant) dismissed-token set is fetched once
 * - shown hint keys are remembered so list pages don't rerender the
 *   same hint card per row
 */
class AlvaHintService
{
    /** @var array<int, string>|null Cache of active modules per request. */
    private ?array $activeModulesCache = null;

    /** @var array<string, true>|null Dismissed-token set, scoped to (user,tenant). */
    private ?array $dismissedTokensCache = null;

    /** @var array<string, true> Hint keys already emitted this request (max-1-per-page-key). */
    private array $shownHintKeys = [];

    /**
     * @param iterable<AlvaHintRuleInterface> $rules
     */
    public function __construct(
        private readonly Security $security,
        private readonly AlvaHintDismissalRepository $dismissalRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ModuleConfigurationService $moduleConfigurationService,
        private readonly iterable $rules = [],
        private readonly ?AlvaHintRenderCountRepository $renderCountRepository = null,
    ) {
    }

    /**
     * Pick the highest-priority undismissed hint for the given entity, or
     * null if no rule applies, the user lacks the required role, or the
     * hint key has already been shown earlier in this request.
     */
    public function pickHintFor(object $entity): ?AlvaHint
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $tenant = $this->resolveTenant($user);
        $activeModules = $this->getActiveModules();

        $candidates = [];
        foreach ($this->rules as $rule) {
            $required = $rule->requiredModules();
            if ($required !== [] && array_diff($required, $activeModules) !== []) {
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

        $dismissedSet = $this->getDismissedTokens($user, $tenant);

        foreach ($candidates as [$tier, $rule]) {
            $hint = $rule->build($entity, $user);

            if (!$this->userHasRequiredRoles($hint)) {
                continue;
            }

            $token = $this->token($hint->key . '@' . $hint->version, $hint->entityType, $hint->entityId);
            if ($hint->dismissible && isset($dismissedSet[$token])) {
                continue;
            }
            if (isset($this->shownHintKeys[$hint->key])) {
                // Same hint key already rendered earlier in this request —
                // listing pages don't repeat the card per row.
                continue;
            }

            $this->shownHintKeys[$hint->key] = true;

            if ($this->renderCountRepository !== null) {
                try {
                    $this->renderCountRepository->increment($this->resolveTenant($user), $hint->key);
                } catch (\Throwable) {
                    // Telemetry failure must never break the hint flow.
                }
            }

            return $hint;
        }

        return null;
    }

    /**
     * Persist a dismissal. Optional `until` enables snooze-instead-of-forever
     * semantics (null = dismissed indefinitely).
     */
    public function dismiss(
        User $user,
        ?Tenant $tenant,
        string $hintKey,
        string $entityType,
        int $entityId,
        ?DateTimeImmutable $until = null,
    ): void {
        $existing = $this->dismissalRepository->findOneFor($user, $tenant, $hintKey, $entityType, $entityId);
        if ($existing instanceof AlvaHintDismissal) {
            $existing->setDismissedAt(new DateTimeImmutable());
            $existing->setDismissedUntil($until);
            $this->entityManager->flush();
            return;
        }

        $dismissal = (new AlvaHintDismissal())
            ->setUser($user)
            ->setTenant($tenant)
            ->setHintKey($hintKey)
            ->setEntityType($entityType)
            ->setEntityId($entityId)
            ->setDismissedUntil($until);

        $this->entityManager->persist($dismissal);
        $this->entityManager->flush();
    }

    private function resolveTenant(User $user): ?Tenant
    {
        return $user->getTenant();
    }

    /**
     * @return array<int, string>
     */
    private function getActiveModules(): array
    {
        return $this->activeModulesCache ??= $this->moduleConfigurationService->getActiveModules();
    }

    /**
     * @return array<string, true>
     */
    private function getDismissedTokens(User $user, ?Tenant $tenant): array
    {
        if ($this->dismissedTokensCache !== null) {
            return $this->dismissedTokensCache;
        }

        $tokens = $this->dismissalRepository->findActiveDismissedTokensForUser($user, $tenant);

        return $this->dismissedTokensCache = array_flip($tokens);
    }

    private function userHasRequiredRoles(AlvaHint $hint): bool
    {
        foreach ($hint->requiredRoles as $role) {
            if (!$this->security->isGranted($role)) {
                return false;
            }
        }
        return true;
    }

    private function token(string $key, string $entityType, int $entityId): string
    {
        return sprintf('%s|%s|%d', $key, $entityType, $entityId);
    }
}
