<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Entity\User;
use App\Service\TenantContext;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * User select for the owner-picker cluster, scoped to the CURRENT tenant.
 *
 * The Doctrine TenantFilter deliberately skips `User`, so a plain EntityType
 * would list (and accept) users of every organisation. This type restricts
 * the choices to users of the current tenant; with no current tenant the list
 * is empty (never "all users").
 */
final class OwnerUserType extends AbstractType
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => User::class,
            'query_builder' => function (EntityRepository $repository): QueryBuilder {
                $qb = $repository->createQueryBuilder('u')->orderBy('u.lastName')->addOrderBy('u.firstName');
                $tenant = $this->tenantContext->getCurrentTenant();
                if ($tenant === null) {
                    return $qb->andWhere('1 = 0');
                }

                return $qb->andWhere('u.tenant = :ownerTenant')->setParameter('ownerTenant', $tenant);
            },
        ]);
    }

    public function getParent(): string
    {
        return EntityType::class;
    }
}
