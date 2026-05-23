<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle\EventListener;

use App\Entity\AuditFinding;
use App\Entity\CorrectiveAction;
use App\Entity\Tenant;
use App\Lifecycle\EventListener\AutoReactionRecapaOnIneffectiveListener;
use App\Service\AutoReactionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Junior-ISB-Audit C4-01 — AutoReactionRecapaOnIneffectiveListener tests.
 */
#[AllowMockObjectsWithoutExpectations]
class AutoReactionRecapaOnIneffectiveListenerTest extends TestCase
{
    private MockObject $em;
    private MockObject $reactions;
    private MockObject $logger;
    private AutoReactionRecapaOnIneffectiveListener $listener;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->reactions = $this->createMock(AutoReactionService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new AutoReactionRecapaOnIneffectiveListener(
            $this->em,
            $this->reactions,
            $this->logger,
        );
    }

    #[Test]
    public function differentWorkflowIsNoOp(): void
    {
        $this->reactions->expects($this->never())->method('isEnabled');
        $this->em->expects($this->never())->method('persist');

        $capa = $this->createCapa(1, $this->createTenant(1), 'Original');
        $event = $this->createEvent(
            $capa,
            workflowName: 'document_lifecycle',
            transitionName: 'verify_ineffective',
        );

        $this->listener->onCompleted($event);
    }

    #[Test]
    public function differentTransitionIsNoOp(): void
    {
        $this->reactions->expects($this->never())->method('isEnabled');
        $this->em->expects($this->never())->method('persist');

        $capa = $this->createCapa(1, $this->createTenant(1), 'Original');
        $event = $this->createEvent(
            $capa,
            workflowName: 'corrective_action_lifecycle',
            transitionName: 'verify_effective',
        );

        $this->listener->onCompleted($event);
    }

    #[Test]
    public function nonCorrectiveActionSubjectIsNoOp(): void
    {
        $this->reactions->expects($this->never())->method('isEnabled');
        $this->em->expects($this->never())->method('persist');

        $event = $this->createEvent(
            new \stdClass(),
            workflowName: 'corrective_action_lifecycle',
            transitionName: 'verify_ineffective',
        );

        $this->listener->onCompleted($event);
    }

    #[Test]
    public function disabledToggleIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(false);
        $this->em->expects($this->never())->method('persist');

        $capa = $this->createCapa(1, $this->createTenant(1), 'Original');
        $event = $this->createEvent(
            $capa,
            workflowName: 'corrective_action_lifecycle',
            transitionName: 'verify_ineffective',
        );

        $this->listener->onCompleted($event);
    }

    #[Test]
    public function existingFollowUpPreventsDuplicate(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $capa = $this->createCapa(7, $this->createTenant(1), 'Failed CAPA');

        $existing = $this->createMock(CorrectiveAction::class);
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects($this->once())->method('findOneBy')->with(['previousCapa' => $capa])->willReturn($existing);

        $this->em->method('getRepository')->willReturn($repo);
        $this->em->expects($this->never())->method('persist');

        $event = $this->createEvent(
            $capa,
            workflowName: 'corrective_action_lifecycle',
            transitionName: 'verify_ineffective',
        );

        $this->listener->onCompleted($event);
    }

    #[Test]
    public function ineffectiveTransitionCreatesFollowUpWithLineage(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(7);
        $finding = $this->createFinding(42, $tenant);
        $capa = $this->createCapa(99, $tenant, 'Original Title', $finding);

        $repo = $this->createMock(EntityRepository::class);
        $repo->expects($this->once())->method('findOneBy')->with(['previousCapa' => $capa])->willReturn(null);
        $this->em->method('getRepository')->willReturn($repo);

        $persisted = [];
        $this->em->method('persist')->willReturnCallback(static function ($e) use (&$persisted) {
            $persisted[] = $e;
        });
        $this->em->method('flush');

        $event = $this->createEvent(
            $capa,
            workflowName: 'corrective_action_lifecycle',
            transitionName: 'verify_ineffective',
        );

        $this->listener->onCompleted($event);

        $followUps = array_values(array_filter(
            $persisted,
            static fn($e) => $e instanceof CorrectiveAction,
        ));
        $this->assertCount(1, $followUps, 'A single follow-up CAPA should be persisted.');

        /** @var CorrectiveAction $followUp */
        $followUp = $followUps[0];
        $this->assertSame($capa, $followUp->getPreviousCapa(), 'Lineage FK must reference the failed CAPA.');
        $this->assertSame($tenant, $followUp->getTenant(), 'Tenant must be inherited.');
        $this->assertSame($finding, $followUp->getFinding(), 'Finding must be inherited.');
        $this->assertSame(CorrectiveAction::STATUS_PLANNED, $followUp->getStatus());
        $this->assertStringContainsString('Folge-CAPA: Original Title', (string) $followUp->getTitle());
        $this->assertSame(CorrectiveAction::ACTION_TYPE_CORRECTIVE, $followUp->getActionType());
    }

    #[Test]
    public function exceptionDuringPersistIsSwallowedAndLogged(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $capa = $this->createCapa(11, $this->createTenant(1), 'Boom');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $this->em->method('getRepository')->willReturn($repo);
        $this->em->method('persist')->willThrowException(new \RuntimeException('db down'));

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $event = $this->createEvent(
            $capa,
            workflowName: 'corrective_action_lifecycle',
            transitionName: 'verify_ineffective',
        );

        // Must NOT throw — the transition itself must remain successful.
        $this->listener->onCompleted($event);
    }

    private function createTenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $ref = (new \ReflectionClass($tenant))->getProperty('id');
        $ref->setValue($tenant, $id);
        return $tenant;
    }

    private function createFinding(int $id, Tenant $tenant): AuditFinding
    {
        $finding = new AuditFinding();
        $ref = (new \ReflectionClass($finding))->getProperty('id');
        $ref->setValue($finding, $id);
        $finding->setTenant($tenant);
        return $finding;
    }

    private function createCapa(int $id, Tenant $tenant, string $title, ?AuditFinding $finding = null): CorrectiveAction
    {
        $capa = new CorrectiveAction();
        $ref = (new \ReflectionClass($capa))->getProperty('id');
        $ref->setValue($capa, $id);
        $capa->setTenant($tenant);
        $capa->setTitle($title);
        if ($finding instanceof AuditFinding) {
            $capa->setFinding($finding);
        }
        return $capa;
    }

    private function createEvent(object $subject, string $workflowName, string $transitionName): CompletedEvent
    {
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('getName')->willReturn($workflowName);

        return new CompletedEvent(
            $subject,
            new Marking([$transitionName === 'verify_ineffective' ? 'verified_ineffective' : 'verified_effective' => 1]),
            new Transition($transitionName, ['completed'], ['verified_ineffective']),
            $workflow,
            [],
        );
    }
}
