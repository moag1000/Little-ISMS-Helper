<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedPolicyApprovalWorkflowCommand;
use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SeedPolicyApprovalWorkflowCommand — Phase 4-C / W3-C.
 *
 * Verifies the 6-step pipeline shape from architecture §9.1 (prepared,
 * ciso_review, dpo_cross_check, function_owner_review, top_mgmt_signoff,
 * published) without booting the full kernel — buildWorkflow() is
 * pure-Doctrine.
 */
#[AllowMockObjectsWithoutExpectations]
class SeedPolicyApprovalWorkflowCommandTest extends TestCase
{
    #[Test]
    public function buildWorkflowProducesSixOrderedSteps(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $command = new SeedPolicyApprovalWorkflowCommand($em);

        $workflow = $command->buildWorkflow();

        self::assertInstanceOf(Workflow::class, $workflow);
        self::assertSame(SeedPolicyApprovalWorkflowCommand::WORKFLOW_NAME, $workflow->getName());
        self::assertSame(SeedPolicyApprovalWorkflowCommand::WORKFLOW_ENTITY_TYPE, $workflow->getEntityType());
        self::assertCount(6, $workflow->getSteps());

        $expected = [
            'prepared',
            'ciso_review',
            'dpo_cross_check',
            'function_owner_review',
            'top_mgmt_signoff',
            'published',
        ];
        $actual = [];
        foreach ($workflow->getSteps() as $step) {
            self::assertInstanceOf(WorkflowStep::class, $step);
            $actual[] = $step->getName();
        }
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function functionOwnerReviewStepIsApprovalWithFunctionOwnerRole(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $command = new SeedPolicyApprovalWorkflowCommand($em);

        $workflow = $command->buildWorkflow();
        $functionOwnerStep = null;
        foreach ($workflow->getSteps() as $step) {
            if ($step->getName() === 'function_owner_review') {
                $functionOwnerStep = $step;
                break;
            }
        }

        self::assertNotNull($functionOwnerStep, 'function_owner_review step must be present (W3 NEW).');
        self::assertSame('approval', $functionOwnerStep->getStepType());
        self::assertSame('ROLE_FUNCTION_OWNER', $functionOwnerStep->getApproverRole());
        self::assertTrue($functionOwnerStep->isRequired());
        self::assertSame('w3', $functionOwnerStep->getMetadata()['introduced_in'] ?? null);
    }
}
