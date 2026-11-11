<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Doctrine\SharedTenantRowsInterface;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\TenantContext;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Gate 72 at run time (T2): a new row of a tenant-owned entity saved without a tenant (since T3 also an
 * existing row whose tenant is set to null) while a person of an organisation works. The TenantFilter
 * adds `tenant_id = <current>` to every query, so such a row is invisible to its own organisation the
 * moment it is written (seen with WorkflowInstance, RiskAppetite). The static gate (PHPStan
 * TenantlessRowRule) reads `new X()` + `setTenant()` and misses factories, `clone` and helper services; this listener sees the row itself.
 *
 * - Tenant-owned = has a `tenant` association, is not Tenant/User and does not implement
 *   {@see SharedTenantRowsInterface} (rows meant for all organisations) — the TenantFilter's rule.
 * - Checked only inside a request whose user belongs to an organisation. Console commands, the
 *   scheduler and workers have no current organisation and pass the tenant explicitly.
 * - In the test environment (`strict`) the flush fails, so every functional test is the runtime
 *   test. Elsewhere (T4, 2026-11; before: a warning and the row saved unchanged, i.e. invisible) the row is
 *   repaired before it is written and the repair logged: a new row gets the organisation of the request, an
 *   existing row keeps the organisation it had.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class TenantlessInsertGuard
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->requestStack->getMainRequest() === null) {
            return;
        }
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $missing = [];
        $repairs = [];
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $meta = $em->getClassMetadata($entity::class);
            if (!self::isTenantOwned($meta->getReflectionClass(), $meta->hasAssociation('tenant'))) {
                continue;
            }
            if ($meta->getFieldValue($entity, 'tenant') === null) {
                $missing[] = $entity::class;
                $repairs[] = [$entity, null];
            }
        }
        // An existing row whose tenant is set to null vanishes for its own organisation like a tenant-less insert.
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $meta = $em->getClassMetadata($entity::class);
            if (!self::isTenantOwned($meta->getReflectionClass(), $meta->hasAssociation('tenant'))) {
                continue;
            }
            $changes = $uow->getEntityChangeSet($entity)['tenant'] ?? null;
            if ($changes !== null && $changes[0] !== null && $changes[1] === null) {
                $missing[] = $entity::class;
                $repairs[] = [$entity, $changes[0]];
            }
        }
        $current = $this->tenantContext->getCurrentTenant();
        if ($missing === [] || !$current instanceof Tenant) {
            return;
        }

        $message = sprintf(
            'New %s saved without an organisation: the TenantFilter hides it from its own organisation. '
            . 'Call setTenant() before persist().',
            implode(', ', array_unique($missing)),
        );
        if ($this->environment === 'test') {
            // @intentional-assertion: strict mode in tests, the flush must fail
            throw new \LogicException($message);
        }
        // T4 (T6 of the counter-check): a warning alone saved the row invisible to its own organisation.
        foreach ($repairs as [$entity, $previous]) {
            $meta = $em->getClassMetadata($entity::class);
            $meta->setFieldValue($entity, 'tenant', $previous ?? $em->getReference(Tenant::class, $current->getId()));
            $uow->recomputeSingleEntityChangeSet($meta, $entity);
        }
        $this->logger->warning($message . ' Repaired: new rows got the current organisation, existing rows kept theirs.', [
            'classes' => array_values(array_unique($missing)),
        ]);
    }

    /** @param \ReflectionClass<covariant object> $class */
    public static function isTenantOwned(\ReflectionClass $class, bool $hasTenantAssociation): bool
    {
        return $hasTenantAssociation
            && !in_array($class->getName(), [Tenant::class, User::class], true)
            && !$class->implementsInterface(SharedTenantRowsInterface::class);
    }
}
