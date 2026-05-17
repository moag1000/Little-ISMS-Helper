<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle\Config;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Lifecycle\Config\LifecycleConfigResolver;
use App\Repository\LifecycleConfigRepository;
use App\Service\TenantContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\SupportStrategy\WorkflowSupportStrategyInterface;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;
use Symfony\Component\Workflow\WorkflowInterface;

class LifecycleConfigResolverTest extends TestCase
{
    public function testYamlOnlyReturnsYamlValue(): void
    {
        $resolver = $this->makeResolver(yamlMeta: ['roles' => ['ROLE_MANAGER'], 'reason_required' => false], overrides: []);
        $doc = new Document();

        $effective = $resolver->resolve($doc, 'document_lifecycle', 'approve');

        $this->assertSame(['ROLE_MANAGER'], $effective['roles']);
        $this->assertFalse($effective['reason_required']);
    }

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

    public function testMissingKeyReturnsDefault(): void
    {
        $resolver = $this->makeResolver(yamlMeta: [], overrides: []);
        $doc = new Document();

        $this->assertSame('fallback', $resolver->get($doc, 'document_lifecycle', 'approve', 'unknown_key', 'fallback'));
    }

    private function makeResolver(array $yamlMeta, array $overrides): LifecycleConfigResolver
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
        $repo->method('findOverridesForTransition')->willReturn($overrides);

        $tenantContext = $this->createStub(TenantContext::class);
        $tenantContext->method('getCurrentTenant')->willReturn(new Tenant());

        return new LifecycleConfigResolver($registry, $repo, $tenantContext);
    }
}
