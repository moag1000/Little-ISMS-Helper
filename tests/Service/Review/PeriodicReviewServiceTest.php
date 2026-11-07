<?php

declare(strict_types=1);

namespace App\Tests\Service\Review;

use App\Entity\Control;
use App\Entity\Document;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Enum\RiskStatus;
use App\Repository\RiskRepository;
use App\Service\AuditLogger;
use App\Service\RiskReviewService;
use App\Service\Review\PeriodicReviewService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Due rule and next-date rule of the periodic review per type.
 */
final class PeriodicReviewServiceTest extends TestCase
{
    private PeriodicReviewService $service;
    private DateTime $today;

    protected function setUp(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $this->service = new PeriodicReviewService(
            new RiskReviewService($this->createStub(RiskRepository::class), $em, new NullLogger()),
            $em,
            $this->createStub(AuditLogger::class),
            $this->createStub(AuthorizationCheckerInterface::class),
        );
        $this->today = new DateTime('2026-11-06');
    }

    #[Test]
    public function riskIsDueWhenDatePassedOrNeverReviewedButNeverWhenClosed(): void
    {
        $risk = (new Risk())->setStatus(RiskStatus::Assessed);
        self::assertTrue($this->service->isDue($risk, $this->today), 'never reviewed');

        $risk->setReviewDate(new DateTime('2026-11-05'));
        self::assertTrue($this->service->isDue($risk, $this->today));

        $risk->setReviewDate(new DateTime('2026-11-06'));
        self::assertFalse($this->service->isDue($risk, $this->today), 'due today is not overdue yet');

        $risk->setStatus(RiskStatus::Closed)->setReviewDate(null);
        self::assertFalse($this->service->isDue($risk, $this->today));
        self::assertFalse($this->service->isReviewable($risk));
    }

    #[Test]
    public function riskNextDateFollowsLevelUnlessTheRiskHasItsOwnInterval(): void
    {
        $risk = (new Risk())->setStatus(RiskStatus::Assessed)->setProbability(4)->setImpact(4); // 16 = high
        self::assertSame('2027-05-05', $this->service->nextReviewDate($risk, $this->today)->format('Y-m-d'));

        $risk->setReviewIntervalDays(14);
        self::assertSame('2026-11-20', $this->service->nextReviewDate($risk, $this->today)->format('Y-m-d'));
    }

    #[Test]
    public function controlKeepsItsCadenceOrFallsBackToTwelveMonths(): void
    {
        $control = (new Control())->setApplicable(true);
        self::assertTrue($this->service->isDue($control, $this->today), 'never reviewed');
        self::assertSame('2027-11-06', $this->service->nextReviewDate($control, $this->today)->format('Y-m-d'));

        $control->setLastReviewDate(new DateTime('2026-01-01'))->setNextReviewDate(new DateTime('2026-07-01'));
        self::assertTrue($this->service->isDue($control, $this->today));
        self::assertSame('2027-05-06', $this->service->nextReviewDate($control, $this->today)->format('Y-m-d'), '181 days');

        $control->setApplicable(false);
        self::assertFalse($this->service->isDue($control, $this->today));
    }

    #[Test]
    public function documentCountsOnlyApprovedOrPublishedAndUsesItsInterval(): void
    {
        $document = (new Document())->setStatus('published')->setReviewIntervalMonths(24);
        self::assertTrue($this->service->isDue($document, $this->today), 'no review scheduled yet');
        self::assertSame('2028-11-06', $this->service->nextReviewDate($document, $this->today)->format('Y-m-d'));

        $document->setNextReviewDate(new DateTime('2027-01-01'));
        self::assertFalse($this->service->isDue($document, $this->today));

        $draft = (new Document())->setStatus('draft');
        self::assertFalse($this->service->isReviewable($draft));
    }

    #[Test]
    public function supplierInEvaluationIsNotOverdueJustForNeverBeingAssessed(): void
    {
        $evaluation = (new Supplier())->setStatus('evaluation');
        self::assertTrue($this->service->isReviewable($evaluation));
        self::assertFalse($this->service->isDue($evaluation, $this->today));

        $active = (new Supplier())->setStatus('active');
        self::assertTrue($this->service->isDue($active, $this->today));

        $active->setLastSecurityAssessment(new DateTime('2025-10-01'))->setNextAssessmentDate(new DateTime('2026-10-01'));
        self::assertTrue($this->service->isDue($active, $this->today));

        $terminated = (new Supplier())->setStatus('terminated')->setNextAssessmentDate(new DateTime('2026-01-01'));
        self::assertFalse($this->service->isDue($terminated, $this->today));
    }

    #[Test]
    public function filterDueWhenLeavesTheListAloneWhenInactive(): void
    {
        $due = (new Risk())->setStatus(RiskStatus::Assessed);
        $current = (new Risk())->setStatus(RiskStatus::Assessed)->setReviewDate(new DateTime('+30 days'));

        self::assertSame([$due, $current], $this->service->filterDueWhen(false, [$due, $current]));
        self::assertSame([$due], $this->service->filterDueWhen(true, [$due, $current]));
    }
}
