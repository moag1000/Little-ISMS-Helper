<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Location;
use App\Entity\Person;
use App\Entity\Tag;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\BulkTagService;
use App\Service\Fte\FteRecorderService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bulk actions of the location map: close the same gap at several sites in
 * one dialog instead of opening every site.
 *
 *  - owner:  set the responsible person (ISO 27001 A.5.2)
 *  - deputy: add a deputy (A.5.2)
 *  - tag:    put the sites into a custom peer group
 *
 * Only sites, people and tags of the given organisation are touched. One
 * logBulk() batch per action (ISO 27001 7.5.3); the saved single edits go to
 * the FTE tracking.
 */
class LocationBulkService
{
    public const array ACTIONS = ['owner', 'deputy', 'tag'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
        private readonly BulkTagService $bulkTags,
        private readonly FteRecorderService $fteRecorder,
    ) {
    }

    /**
     * @param list<int> $siteIds
     *
     * @return int number of sites changed
     */
    public function apply(Tenant $tenant, User $actor, string $action, array $siteIds, int $targetId): int
    {
        $sites = $siteIds === [] ? [] : $this->em->getRepository(Location::class)->findBy(['id' => $siteIds, 'tenant' => $tenant]);
        if ($sites === [] || !in_array($action, self::ACTIONS, true)) {
            return 0;
        }

        $changed = match ($action) {
            'owner' => $this->assignOwner($tenant, $sites, $targetId),
            'deputy' => $this->addDeputy($tenant, $sites, $targetId),
            'tag' => $this->tag($tenant, $actor, $sites, $targetId),
        };
        if ($changed > 0) {
            $this->fteRecorder->recordBulkAction($changed, 'Location', $action, $tenant);
        }

        return $changed;
    }

    /** @param list<Location> $sites */
    private function assignOwner(Tenant $tenant, array $sites, int $userId): int
    {
        $user = $this->em->find(User::class, $userId);
        if (!$user instanceof User || $user->getTenant()?->getId() !== $tenant->getId()) {
            return 0;
        }

        $rows = [];
        foreach ($sites as $site) {
            if ($site->getResponsiblePersonUser()?->getId() === $user->getId()) {
                continue;
            }
            $rows[] = [
                'entity_id' => $site->getId(),
                'action' => 'update',
                'old_values' => ['responsiblePersonUser' => $site->getResponsiblePersonUser()?->getId()],
                'new_values' => ['responsiblePersonUser' => $user->getId()],
            ];
            $site->setResponsiblePersonUser($user);
        }

        return $this->flush($rows, 'owner');
    }

    /** @param list<Location> $sites */
    private function addDeputy(Tenant $tenant, array $sites, int $personId): int
    {
        $person = $this->em->find(Person::class, $personId);
        if (!$person instanceof Person || $person->getTenant()?->getId() !== $tenant->getId()) {
            return 0;
        }

        $rows = [];
        foreach ($sites as $site) {
            if ($site->getResponsibleDeputyPersons()->contains($person)) {
                continue;
            }
            $site->addResponsibleDeputyPerson($person);
            $rows[] = [
                'entity_id' => $site->getId(),
                'action' => 'update',
                'new_values' => ['responsibleDeputyPersons' => ['added' => $person->getId()]],
            ];
        }

        return $this->flush($rows, 'deputy');
    }

    /** @param list<Location> $sites */
    private function tag(Tenant $tenant, User $actor, array $sites, int $tagId): int
    {
        $tag = $this->em->find(Tag::class, $tagId);
        $tagTenant = $tag?->getTenant();
        if (!$tag instanceof Tag || ($tagTenant !== null && $tagTenant->getId() !== $tenant->getId())) {
            return 0;
        }

        return $this->bulkTags->applyTags($sites, [$tagId], $actor)['added'];
    }

    /** @param list<array<string, mixed>> $rows */
    private function flush(array $rows, string $action): int
    {
        if ($rows === []) {
            return 0;
        }
        $this->em->flush();
        $this->auditLogger->logBulk('location.map.bulk_' . $action, 'Location', ['action' => $action], $rows);

        return count($rows);
    }
}
