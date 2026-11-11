<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle\Config;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Lifecycle\Config\LifecycleConfigResolver;
use App\Repository\LifecycleConfigRepository;
use App\Service\TenantContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\SupportStrategy\WorkflowSupportStrategyInterface;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;
use Symfony\Component\Workflow\WorkflowInterface;
use PHPUnit\Framework\Attributes\Test;

class LifecycleConfigResolverTest extends TestCase
{
    #[Test]
    public function testYamlOnlyReturnsYamlValue(): void
    {
        $resolver = $this->makeResolver(yamlMeta: ['roles' => ['ROLE_MANAGER'], 'reason_required' => false], overrides: []);
        $doc = new Document();

        $effective = $resolver->resolve($doc, 'document_lifecycle', 'approve');

        $this->assertSame(['ROLE_MANAGER'], $effective['roles']);
        $this->assertFalse($effective['reason_required']);
    }

    #[Test]
    public function testDbOverlayOverridesYaml(): void
    {
        $resolver = $this->makeResolver(
            yamlMeta: ['roles' => ['ROLE_MANAGER'], 'reason_required' => false],
            overrides: ['reason_required' => true, 'roles' => ['ROLE_ADMIN']],
        );
        $doc = new Document();

        $effective = $resolver->resolve($doc, 'document_lifecycle', 'approve');

        $this->assertSame(['ROLE_ADMIN'], $effective['roles']);
        $this->assertTrue($effective['reason_required']);
    }

    #[Test]
    public function testMissingKeyReturnsDefault(): void
    {
        $resolver = $this->makeResolver(yamlMeta: [], overrides: []);
        $doc = new Document();

        $this->assertSame('fallback', $resolver->get($doc, 'document_lifecycle', 'approve', 'unknown_key', 'fallback'));
    }

    #[Test]
    public function overridesComeFromTheRecordsOrganisationNotTheCurrentOne(): void
    {
        // S15: a holding user acting in organisation B, or a job without a current
        // tenant, must get the rules of the record's organisation.
        $recordTenant = new Tenant();
        $seen = [];
        $resolver = $this->makeResolver(
            yamlMeta: ['roles' => ['ROLE_MANAGER']],
            overrides: ['roles' => ['ROLE_CISO']],
            currentTenant: null,
            seenTenants: $seen,
        );
        $doc = (new Document())->setTenant($recordTenant);

        $effective = $resolver->resolve($doc, 'document_lifecycle', 'approve');

        $this->assertSame(['ROLE_CISO'], $effective['roles']);
        $this->assertSame([$recordTenant], $seen);
    }

    #[Test]
    public function storedOverridesThatLoosenANormDutyAreIgnored(): void
    {
        // Rows written before S15 (free-text roles, four eyes switched off) have no effect.
        $resolver = $this->makeResolver(
            yamlMeta: ['roles' => ['ROLE_MANAGER'], 'four_eyes' => true, 'reason_required' => true, 'module' => 'documents'],
            overrides: ['roles' => ['ROLE_USER'], 'four_eyes' => false, 'reason_required' => false, 'module' => 'core'],
        );

        $effective = $resolver->resolve(new Document(), 'document_lifecycle', 'approve');

        $this->assertSame(['ROLE_MANAGER'], $effective['roles']);
        $this->assertTrue($effective['four_eyes']);
        $this->assertTrue($effective['reason_required']);
        $this->assertSame('documents', $effective['module']);
    }

    #[Test]
    public function managementRolesCannotBeHandedToAdmins(): void
    {
        $resolver = $this->makeResolver(
            yamlMeta: ['roles' => ['ROLE_TOP_MGMT']],
            overrides: ['roles' => ['ROLE_ADMIN']],
        );

        $this->assertSame(['ROLE_TOP_MGMT'], $resolver->resolve(new Document(), 'document_lifecycle', 'approve')['roles']);
    }

    /** @param list<Tenant|null> $seenTenants */
    private function makeResolver(
        array $yamlMeta,
        array $overrides,
        ?Tenant $currentTenant = new Tenant(),
        array &$seenTenants = [],
    ): LifecycleConfigResolver
    {
        $transition = new Transition('approve', ['in_review'], ['approved']);

        $transitionsMeta = new \SplObjectStorage();
        $transitionsMeta->offsetSet($transition, $yamlMeta);

        $store = new InMemoryMetadataStore([], [], $transitionsMeta);
        $definition = new Definition(['in_review', 'approved'], [$transition], 'in_review', $store);
        $workflow = new Workflow($definition, name: 'document_lifecycle');

        $registry = new Registry();
        $strategy = new class implements WorkflowSupportStrategyInterface {
            public function supports(WorkflowInterface $workflow, object $subject): bool { return true; }
        };
        $registry->addWorkflow($workflow, $strategy);

        $repo = $this->createStub(LifecycleConfigRepository::class);
        $repo->method('findOverridesForTransition')->willReturnCallback(
            static function (Tenant $tenant) use ($overrides, &$seenTenants): array {
                $seenTenants[] = $tenant;

                return $overrides;
            },
        );

        $tenantContext = $this->createStub(TenantContext::class);
        $tenantContext->method('getCurrentTenant')->willReturn($currentTenant);

        // S28: an override narrows the YAML circle through the hierarchy (CISO/admin reach the manager)
        $hierarchy = new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_MANAGER'], 'ROLE_CISO' => ['ROLE_MANAGER']]);

        return new LifecycleConfigResolver($registry, $repo, $tenantContext, $hierarchy);
    }
}
