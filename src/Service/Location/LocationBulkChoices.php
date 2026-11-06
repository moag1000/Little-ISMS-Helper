<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Choices for the bulk actions of the location map: users and people of the
 * organisation, and the tags it may use as peer groups.
 */
readonly class LocationBulkChoices
{
    public function __construct(
        private EntityManagerInterface $em,
        private TagRepository $tags,
    ) {
    }

    /**
     * @return array{owner: array<int, string>, deputy: array<int, string>, tag: array<int, string>}
     */
    public function forTenant(Tenant $tenant): array
    {
        $users = [];
        foreach ($this->em->getRepository(User::class)->findBy(['tenant' => $tenant, 'isActive' => true], ['lastName' => 'ASC']) as $user) {
            $users[(int) $user->getId()] = $user->getFullName();
        }
        $people = [];
        foreach ($this->em->getRepository(Person::class)->findBy(['tenant' => $tenant], ['fullName' => 'ASC']) as $person) {
            $people[(int) $person->getId()] = $person->getDisplayName();
        }
        $tags = [];
        foreach ($this->tags->findVisibleFor($tenant) as $tag) {
            $tags[(int) $tag->getId()] = $tag->getName();
        }

        return ['owner' => $users, 'deputy' => $people, 'tag' => $tags];
    }
}
