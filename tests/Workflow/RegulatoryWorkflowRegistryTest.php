<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Repository\WorkflowRepository;
use App\Security\RoleNames;
use App\Service\Planning\Source\Adapter\WorkflowInstanceAdapter;
use App\Workflow\Loader\RegulatoryWorkflowLoader;
use App\Workflow\Mirror\RegulatoryWorkflowMirror;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * S17 — durable test over the real workflow registry of the booted kernel.
 *
 * The approval steps of the regulatory chains once sat where the loader did not look
 * (`metadata.steps` vs. `metadata.regulatory_metadata.steps`): the loader returned null for
 * every chain, approvals ran on stale database rows, and every test used a stub in the loader's
 * layout. This test reads what the application loads:
 *
 *  - every config/workflows/regulatory/*.yaml chain yields at least one step,
 *  - every `approver_role` is a role the app knows (or the `owner` keyword),
 *  - the database rows written by {@see RegulatoryWorkflowMirror} equal the YAML, and a row
 *    that drifted (old role) is reported and aligned in place (step ids stay).
 */
final class RegulatoryWorkflowRegistryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    #[Test]
    public function everyRegulatoryYamlChainYieldsStepsFromTheRegistry(): void
    {
        $loader = self::getContainer()->get(RegulatoryWorkflowLoader::class);
        $names = self::yamlChainNames();

        self::assertCount(15, $names, 'config/workflows/regulatory holds the 15 regulatory chains');
        self::assertEqualsCanonicalizing($names, $loader->getRegisteredRegulatoryWorkflowNames());
        foreach ($names as $name) {
            $steps = $loader->getStepsForWorkflow($name);
            self::assertNotNull($steps, "{$name}: the loader finds no steps — check the layout (metadata.steps)");
            self::assertGreaterThanOrEqual(1, count($steps), $name);
        }
    }

    #[Test]
    public function everyApproverRoleExists(): void
    {
        $loader = self::getContainer()->get(RegulatoryWorkflowLoader::class);
        foreach (self::yamlChainNames() as $name) {
            foreach ($loader->getStepsForWorkflow($name) ?? [] as $index => $step) {
                $role = (string) ($step['approver_role'] ?? '');
                self::assertTrue(
                    $role === WorkflowInstanceAdapter::APPROVER_OWNER || in_array($role, RoleNames::RESERVED, true),
                    sprintf('%s step %d waits on unknown role "%s"', $name, $index + 1, $role),
                );
            }
        }
    }

    #[Test]
    public function theDatabaseRowsEqualTheYamlAfterAlignment(): void
    {
        $mirror = self::getContainer()->get(RegulatoryWorkflowMirror::class);
        $loader = self::getContainer()->get(RegulatoryWorkflowLoader::class);

        $mirror->sync();

        self::assertSame([], $mirror->drift());
        foreach (self::yamlChainNames() as $name) {
            $row = $this->row($loader, $name);
            $expected = array_map(static fn (array $step): string => (string) $step['approver_role'], $loader->getStepsForWorkflow($name) ?? []);
            $actual = array_map(static fn (WorkflowStep $step): string => (string) $step->getApproverRole(), array_values($row->getSteps()->toArray()));
            self::assertSame($expected, $actual, "{$name}: database approvers equal the YAML");
        }
    }

    #[Test]
    public function aDriftedRoleIsReportedAndAlignedInPlace(): void
    {
        $mirror = self::getContainer()->get(RegulatoryWorkflowMirror::class);
        $loader = self::getContainer()->get(RegulatoryWorkflowLoader::class);
        $mirror->sync();

        // The state of a database written before W5: the risk treatment chain still waits on the
        // risk manager instead of the risk owner.
        $row = $this->row($loader, 'risk_treatment');
        $first = $row->getSteps()->first();
        self::assertInstanceOf(WorkflowStep::class, $first);
        $stepId = $first->getId();
        $first->setApproverRole('ROLE_RISK_MANAGER');
        $this->entityManager->flush();

        $drift = $mirror->drift();
        self::assertCount(1, $drift);
        self::assertSame('risk_treatment', $drift[0]['workflow']);
        self::assertSame('differs', $drift[0]['state']);
        self::assertStringContainsString('ROLE_RISK_MANAGER → owner', implode("\n", $drift[0]['differences']));

        $result = $mirror->sync();

        self::assertSame(1, $result['updated']);
        self::assertSame([], $mirror->drift());
        $this->entityManager->clear();
        $aligned = $this->entityManager->find(WorkflowStep::class, $stepId);
        self::assertInstanceOf(WorkflowStep::class, $aligned, 'the step keeps its id (running approvals point at it)');
        self::assertSame(WorkflowInstanceAdapter::APPROVER_OWNER, $aligned->getApproverRole());
    }

    #[Test]
    public function aMissingRowIsCreatedOnDemand(): void
    {
        $mirror = self::getContainer()->get(RegulatoryWorkflowMirror::class);
        $loader = self::getContainer()->get(RegulatoryWorkflowLoader::class);
        $mirror->sync();
        $row = $this->row($loader, 'capa');
        foreach ($this->entityManager->getRepository(WorkflowInstance::class)->findBy(['workflow' => $row]) as $instance) {
            $this->entityManager->remove($instance);
        }
        $this->entityManager->remove($row);
        $this->entityManager->flush();

        self::assertSame('missing', array_column($mirror->drift(), 'state', 'workflow')['capa'] ?? null);

        $created = $mirror->ensure('capa');
        $this->entityManager->flush();

        self::assertInstanceOf(Workflow::class, $created);
        self::assertCount(count($loader->getStepsForWorkflow('capa') ?? []), $created->getSteps());
        self::assertNull($mirror->ensure('no_such_chain'));
    }

    private function row(RegulatoryWorkflowLoader $loader, string $name): Workflow
    {
        $meta = $loader->getRegulatoryMetadata($name) ?? [];
        $row = self::getContainer()->get(WorkflowRepository::class)->findOneBy([
            'name' => $meta['title'] ?? '',
            'entityType' => $meta['entity_type'] ?? '',
            'tenant' => null,
        ]);
        self::assertInstanceOf(Workflow::class, $row, "{$name}: database row exists");

        return $row;
    }

    /** @return list<string> */
    private static function yamlChainNames(): array
    {
        return array_map(
            static fn (string $file): string => basename($file, '.yaml'),
            glob(\dirname(__DIR__, 2) . '/config/workflows/regulatory/*.yaml') ?: [],
        );
    }
}
