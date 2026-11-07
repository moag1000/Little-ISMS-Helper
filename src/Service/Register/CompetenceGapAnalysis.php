<?php

declare(strict_types=1);

namespace App\Service\Register;

use App\Entity\IsmsRegisterEntry;
use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\TrainingParticipation;
use App\Entity\User;
use App\Enum\RegisterType;
use App\Repository\IsmsRegisterEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ISO 27001 7.2 b/c: does the holder of each ISMS role have the competences the
 * role requires? Computed, nothing to maintain twice: the required competences
 * are register entries linked to the role, the evidence is what already exists —
 * the competencies on the user profile and completed participations in a
 * training linked to the competence.
 */
final class CompetenceGapAnalysis
{
    public function __construct(
        private readonly IsmsRegisterEntryRepository $entries,
        private readonly RegisterLinkTypes $linkTypes,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<array{competence: IsmsRegisterEntry, covered: bool, evidence: ?string}>
     */
    public function forRole(IsmsRegisterEntry $role): array
    {
        if ($role->getType() !== RegisterType::ROLE) {
            return [];
        }
        $holder = $role->getOwnerUser();
        $rows = [];
        foreach ($this->linkTypes->load('competence_entry', $role->linkedIds('competence_entry')) as $competence) {
            if (!$competence instanceof IsmsRegisterEntry || $competence->isArchived()) {
                continue;
            }
            $evidence = $holder instanceof User ? $this->evidence($holder, $competence) : null;
            $rows[] = ['competence' => $competence, 'covered' => $evidence !== null, 'evidence' => $evidence];
        }

        return $rows;
    }

    /**
     * Open gaps over all active roles.
     *
     * @return list<array{role: IsmsRegisterEntry, competence: IsmsRegisterEntry}>
     */
    public function gaps(Tenant $tenant): array
    {
        $gaps = [];
        foreach ($this->entries->findByType($tenant, RegisterType::ROLE) as $role) {
            foreach ($this->forRole($role) as $row) {
                if (!$row['covered']) {
                    $gaps[] = ['role' => $role, 'competence' => $row['competence']];
                }
            }
        }

        return $gaps;
    }

    /** What proves the competence for this user: a profile competency or a completed training. */
    public function evidence(User $user, IsmsRegisterEntry $competence): ?string
    {
        $wanted = self::normalize((string) $competence->getTitle());
        foreach ($user->getCompetencies() ?? [] as $row) {
            $name = self::normalize((string) ($row['name'] ?? ''));
            if ($name !== '' && $wanted !== '' && (str_contains($name, $wanted) || str_contains($wanted, $name))) {
                return (string) $row['name'];
            }
        }
        $trainingIds = $competence->linkedIds('training');
        if ($trainingIds === []) {
            return null;
        }
        $participation = $this->entityManager->getRepository(TrainingParticipation::class)->findOneBy([
            'user' => $user,
            'training' => $trainingIds,
            'status' => TrainingParticipation::STATUS_COMPLETED,
        ]);
        $training = $participation?->getTraining();

        return $training instanceof Training ? (string) $training->getTitle() : null;
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }
}
