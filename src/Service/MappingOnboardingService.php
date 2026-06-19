<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ComplianceMappingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Drives the guided mapping-onboarding workflow. Completion is derived from real
 * timestamps/counts vs a per-user start-snapshot, so pre-existing data never
 * auto-completes a step (the user must act DURING the workflow).
 */
class MappingOnboardingService
{
    public const STEP_IDS = ['laden', 'reviewen', 'mappen', 'wiederverwenden'];

    public function __construct(
        private readonly ?ComplianceMappingRepository $mappingRepository = null,
        private readonly ?EntityManagerInterface $entityManager = null,
    ) {
    }

    /**
     * @param array{startedAt:string, mappingCount:int} $snapshot
     * @param array{mappingCount:int, latestReviewedAt:?string, latestCreatedAt:?string, signals:array<string,bool>} $data
     */
    public function isStepCompleteFrom(string $stepId, array $snapshot, array $data): bool
    {
        $startedAt = strtotime($snapshot['startedAt']);

        return match ($stepId) {
            'laden' => $data['mappingCount'] > $snapshot['mappingCount'],
            'reviewen' => $data['latestReviewedAt'] !== null && strtotime($data['latestReviewedAt']) > $startedAt,
            'mappen' => $data['latestCreatedAt'] !== null && strtotime($data['latestCreatedAt']) > $startedAt,
            'wiederverwenden' => !empty($data['signals']['crossFrameworkSeen']),
            default => false,
        };
    }

    /** @return array<string, mixed> */
    public function state(User $user, Tenant $tenant): array
    {
        $state = $user->getMappingOnboardingState();
        if (!isset($state['snapshot'])) {
            $state = [
                'step' => 0,
                'completed' => [],
                'snapshot' => [
                    'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'mappingCount' => $this->mappingRepository?->countByTenant($tenant) ?? 0,
                ],
                'signals' => [],
            ];
            $user->setMappingOnboardingState($state);
            $this->entityManager?->flush();
        }

        return $state;
    }

    public function markSignal(User $user, string $signal): void
    {
        $state = $user->getMappingOnboardingState();
        $state['signals'][$signal] = true;
        $user->setMappingOnboardingState($state);
        $this->entityManager?->flush();
    }

    /** @return array<string, mixed> */
    public function advance(User $user, Tenant $tenant): array
    {
        $state = $this->state($user, $tenant);
        $stepIndex = (int) ($state['step'] ?? 0);
        if ($stepIndex >= count(self::STEP_IDS)) {
            return $state;
        }
        $stepId = self::STEP_IDS[$stepIndex];
        $data = $this->liveData($tenant, $state);
        if ($this->isStepCompleteFrom($stepId, $state['snapshot'], $data)) {
            if (!in_array($stepId, $state['completed'], true)) {
                $state['completed'][] = $stepId;
            }
            $state['step'] = $stepIndex + 1;
            $user->setMappingOnboardingState($state);
            $this->entityManager?->flush();
        }

        return $state;
    }

    public function reset(User $user): void
    {
        $user->setMappingOnboardingState([]);
        $this->entityManager?->flush();
    }

    /**
     * @param array<string, mixed> $state
     * @return array{mappingCount:int, latestReviewedAt:?string, latestCreatedAt:?string, signals:array<string,bool>}
     */
    private function liveData(Tenant $tenant, array $state): array
    {
        $repo = $this->mappingRepository;

        return [
            'mappingCount' => $repo?->countByTenant($tenant) ?? 0,
            'latestReviewedAt' => $repo?->latestReviewedAtForTenant($tenant)?->format(\DateTimeInterface::ATOM),
            'latestCreatedAt' => $repo?->latestCreatedAtForTenant($tenant)?->format(\DateTimeInterface::ATOM),
            'signals' => $state['signals'] ?? [],
        ];
    }
}
