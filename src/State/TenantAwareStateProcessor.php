<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * API Platform state processor that enforces tenant isolation on write operations.
 *
 * - POST: Sets the entity's tenant to the current user's tenant, ignoring
 *   any client-supplied tenant value (prevents cross-tenant injection).
 * - PUT/PATCH/DELETE: Delegates to the inner processor (tenant validation
 *   is handled by ApiTenantVoter via security expressions).
 *
 * PenTest Finding PT-005: Without this processor, a POST request could
 * set tenant_id to another tenant's ID, creating data in foreign tenants.
 */
final readonly class TenantAwareStateProcessor implements ProcessorInterface
{
    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private ProcessorInterface $removeProcessor,
        private TenantContext $tenantContext,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        // On POST: force tenant to current user's tenant
        if ($operation instanceof Post && is_object($data) && method_exists($data, 'setTenant')) {
            $tenant = $this->tenantContext->getCurrentTenant();
            if (!$tenant instanceof Tenant) {
                $user = $this->security->getUser();
                if ($user instanceof User) {
                    $tenant = $user->getTenant();
                }
            }

            if ($tenant instanceof Tenant) {
                $data->setTenant($tenant);
            }
        }

        // Delegate to appropriate inner processor
        if ($operation instanceof \ApiPlatform\Metadata\Delete) {
            return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
