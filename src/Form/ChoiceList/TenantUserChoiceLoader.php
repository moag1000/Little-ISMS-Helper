<?php

declare(strict_types=1);

namespace App\Form\ChoiceList;

use App\Entity\Tenant;
use App\Entity\User;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\ChoiceList\DoctrineChoiceLoader;
use Symfony\Bridge\Doctrine\Form\ChoiceList\IdReader;
use Symfony\Bridge\Doctrine\Form\ChoiceList\ORMQueryBuilderLoader;
use Symfony\Component\Form\ChoiceList\ChoiceListInterface;
use Symfony\Component\Form\ChoiceList\Loader\ChoiceLoaderInterface;

/**
 * Choice loader of {@see \App\Form\Type\OwnerUserType}: the users of ONE
 * organisation, decided lazily when the choices are first needed (render or
 * submit) — by then the bound record is known.
 *
 * Tenant precedence: explicit `tenant` option → the bound record's tenant →
 * the current tenant → none (empty list, never "all users"). The submitted
 * ids are resolved through the same scoped query, so a user of another
 * organisation is rejected as an invalid choice.
 */
final class TenantUserChoiceLoader implements ChoiceLoaderInterface
{
    private Tenant|int|null $recordTenant = null;
    private ?ChoiceLoaderInterface $inner = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?IdReader $idReader,
        private readonly TenantContext $tenantContext,
        private readonly ?Tenant $fixedTenant = null,
    ) {
    }

    /**
     * Called with the bound record's organisation (entity or id, see
     * RecordTenant::of()) before the choices are loaded.
     */
    public function setRecordTenant(Tenant|int|null $tenant): void
    {
        if ($tenant !== $this->recordTenant) {
            $this->recordTenant = $tenant;
            $this->inner = null;
        }
    }

    public function getScopeTenant(): Tenant|int|null
    {
        return $this->fixedTenant ?? $this->recordTenant ?? $this->tenantContext->getCurrentTenant();
    }

    public function loadChoiceList(?callable $value = null): ChoiceListInterface
    {
        return $this->inner()->loadChoiceList($value);
    }

    public function loadChoicesForValues(array $values, ?callable $value = null): array
    {
        return $this->inner()->loadChoicesForValues($values, $value);
    }

    public function loadValuesForChoices(array $choices, ?callable $value = null): array
    {
        return $this->inner()->loadValuesForChoices($choices, $value);
    }

    private function inner(): ChoiceLoaderInterface
    {
        if ($this->inner === null) {
            $qb = $this->em->getRepository(User::class)->createQueryBuilder('u')
                ->orderBy('u.lastName')
                ->addOrderBy('u.firstName');
            $tenant = $this->getScopeTenant();
            if ($tenant === null) {
                $qb->andWhere('1 = 0');
            } else {
                $qb->andWhere('u.tenant = :ownerTenant')->setParameter('ownerTenant', $tenant);
            }
            $this->inner = new DoctrineChoiceLoader($this->em, User::class, $this->idReader, new ORMQueryBuilderLoader($qb));
        }

        return $this->inner;
    }
}
