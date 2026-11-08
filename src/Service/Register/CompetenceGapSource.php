<?php

declare(strict_types=1);

namespace App\Service\Register;

use App\Entity\Tenant;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use Closure;
use DateTimeInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * ISO 27001 7.2 b/c: an ISMS role whose holder lacks a required competence
 * ({@see CompetenceGapAnalysis}). One duty per role, open while a gap is
 * open, no date: it ends when the competence is on the profile or a linked
 * training is completed. The holder acts; the ISB is kept informed (7.2 d,
 * "take actions to acquire the necessary competence").
 */
final class CompetenceGapSource implements DeadlineSource
{
    /**
     * @param (Closure(): CompetenceGapAnalysis)|null $analysis lazy: the analysis reads trainings and registers
     */
    public function __construct(
        #[AutowireServiceClosure(CompetenceGapAnalysis::class)]
        private readonly ?Closure $analysis = null,
    ) {
    }

    public function slug(): string
    {
        return 'competence_gap';
    }

    public function label(): string
    {
        return 'Kompetenzlücke';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    /** @return iterable<CompetenceGap> */
    public function findOpen(Tenant $tenant): iterable
    {
        if ($this->analysis === null) {
            return [];
        }
        $byRole = [];
        foreach (($this->analysis)()->gaps($tenant) as ['role' => $role, 'competence' => $competence]) {
            $id = (int) $role->getId();
            $byRole[$id] ??= new CompetenceGap($role, []);
            $byRole[$id] = new CompetenceGap($role, [...$byRole[$id]->missing, (string) $competence->getTitle()]);
        }

        return array_values($byRole);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        return null;
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof CompetenceGap);

        return sprintf('%s: %s', (string) $item->role->getTitle(), implode(', ', $item->missing));
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof CompetenceGap);

        return $item->missing === [] || $item->role->isArchived();
    }

    public function refId(object $item): int
    {
        assert($item instanceof CompetenceGap);

        return (int) $item->role->getId();
    }

    public function supports(object $item): bool
    {
        return $item instanceof CompetenceGap;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof CompetenceGap);

        return Responsibility::holders($item->role->getOwnerUser(), $item->role->getOwnerPerson())
            ->withDeputies(...$item->role->getDeputyPersons()->toArray())
            ->orRoles('ROLE_ISB', 'ROLE_CISO')
            ->alsoInform('ROLE_ISB');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof CompetenceGap);

        return new RecordLink('app_register_entry_show', ['id' => (int) $item->role->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'competence_gaps', leadDays: null, normReference: 'ISO 27001 7.2 · NIS-2 Art. 20 (2)');
    }
}
