<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Supplier;
use App\Entity\SupplierQuestionnaire;
use App\Entity\Tenant;
use App\Service\AuditLogger;
use App\Service\SupplierQuestionnaireService;
use App\Tests\Lifecycle\Support\YamlWorkflowLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F23 — outbound questionnaire lifecycle (DB-free, mocked EM + audit logger).
 */
#[AllowMockObjectsWithoutExpectations]
final class SupplierQuestionnaireServiceTest extends TestCase
{
    private YamlWorkflowLifecycle $lifecycle;

    private function service(): SupplierQuestionnaireService
    {
        return new SupplierQuestionnaireService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AuditLogger::class),
            $this->lifecycle = new YamlWorkflowLifecycle(),
        );
    }

    #[Test]
    public function createAndSendMintsTokenAndMarksSent(): void
    {
        $q = $this->service()->createAndSend(
            new Tenant(),
            (new Supplier())->setName('ACME'),
            'DORA due diligence',
            [['id' => 'iso27001', 'text' => 'Certified?']],
        );

        self::assertSame(SupplierQuestionnaire::STATUS_SENT, $q->getStatus());
        self::assertSame(64, strlen($q->getPublicToken())); // 32 bytes hex
        self::assertNotNull($q->getSentAt());
        self::assertSame('DORA due diligence', $q->getTitle());
        self::assertSame(['send'], $this->lifecycle->appliedTransitions());
    }

    #[Test]
    public function submitResponseRecordsAnswersAndCompletes(): void
    {
        $q = (new SupplierQuestionnaire())
            ->setStatus(SupplierQuestionnaire::STATUS_SENT)
            ->setQuestions([['id' => 'iso27001', 'text' => 'Certified?'], ['id' => 'bcm', 'text' => 'BCM?']]);

        $accepted = $this->service()->submitResponse($q, ['iso27001' => 'Yes, scope X', 'bogus' => 'ignored', 'bcm' => 'Tested annually']);

        self::assertTrue($accepted);
        self::assertSame(SupplierQuestionnaire::STATUS_COMPLETED, $q->getStatus());
        self::assertSame(['iso27001' => 'Yes, scope X', 'bcm' => 'Tested annually'], $q->getAnswers());
        self::assertArrayNotHasKey('bogus', $q->getAnswers(), 'unknown question ids must be dropped');
        self::assertNotNull($q->getCompletedAt());
        self::assertSame(['complete'], $this->lifecycle->appliedTransitions());
    }

    #[Test]
    public function submitResponseRejectedWhenNotOpen(): void
    {
        $q = (new SupplierQuestionnaire())->setStatus(SupplierQuestionnaire::STATUS_COMPLETED);

        self::assertFalse($this->service()->submitResponse($q, ['x' => 'y']));
    }
}
