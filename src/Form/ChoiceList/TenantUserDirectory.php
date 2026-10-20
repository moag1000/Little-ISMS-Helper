<?php

declare(strict_types=1);

namespace App\Form\ChoiceList;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped list of the users of one organisation, shared by every
 * OwnerUserType field of a request (a form with three user pickers queries
 * the organisation's users once). Reset after each request (kernel.reset);
 * a list whose entities were detached by EntityManager::clear() is reloaded.
 */
final class TenantUserDirectory implements ResetInterface
{
    /** @var array<int, list<User>> tenant id => users */
    private array $byTenant = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Users of the organisation (entity or id), ordered by last/first name.
     *
     * @return list<User>
     */
    public function usersOf(Tenant|int $tenant): array
    {
        $key = $tenant instanceof Tenant ? $tenant->getId() : $tenant;
        if ($key === null) {
            return $this->query($tenant); // unsaved tenant: no caching
        }
        $cached = $this->byTenant[$key] ?? null;
        if ($cached !== null && ($cached === [] || $this->em->contains($cached[0]))) {
            return $cached;
        }

        return $this->byTenant[$key] = $this->query($tenant);
    }

    public function reset(): void
    {
        $this->byTenant = [];
    }

    /** @return list<User> */
    private function query(Tenant|int $tenant): array
    {
        /** @var list<User> $users */
        $users = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->andWhere('u.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('u.lastName')
            ->addOrderBy('u.firstName')
            ->getQuery()
            ->getResult();

        return $users;
    }
}
