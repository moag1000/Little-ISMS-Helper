<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Document;
use App\Entity\DoraExitPlan;
use App\Entity\Supplier;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DoraExitPlanTest extends TestCase
{
    #[Test]
    public function defaultsAreSensible(): void
    {
        $plan = new DoraExitPlan();
        self::assertSame(DoraExitPlan::TRIGGER_PLANNED_RENEWAL, $plan->getExitTrigger());
        self::assertFalse($plan->isDataDeletionConfirmation());
        self::assertNull($plan->getTestedAt());
        self::assertNull($plan->getEstimatedDurationDays());
        self::assertNull($plan->getEstimatedCost());
        self::assertNotNull($plan->getCreatedAt());
        self::assertNull($plan->getUpdatedAt());
    }

    #[Test]
    public function settersAndGettersRoundtrip(): void
    {
        $supplier = new Supplier();
        $doc = new Document();
        $tested = new DateTimeImmutable('2025-04-01 10:00:00');

        $plan = (new DoraExitPlan())
            ->setSupplier($supplier)
            ->setExitTrigger(DoraExitPlan::TRIGGER_INSOLVENCY)
            ->setDataReturnFormat('CSV via SFTP within 30 days')
            ->setDataDeletionConfirmation(true)
            ->setDeletionCertificateDoc($doc)
            ->setMigrationPath('Cut over to in-house Kubernetes by Q3')
            ->setTestedAt($tested)
            ->setEstimatedDurationDays(90)
            ->setEstimatedCost('75000.00');

        self::assertSame($supplier, $plan->getSupplier());
        self::assertSame(DoraExitPlan::TRIGGER_INSOLVENCY, $plan->getExitTrigger());
        self::assertSame('CSV via SFTP within 30 days', $plan->getDataReturnFormat());
        self::assertTrue($plan->isDataDeletionConfirmation());
        self::assertSame($doc, $plan->getDeletionCertificateDoc());
        self::assertSame('Cut over to in-house Kubernetes by Q3', $plan->getMigrationPath());
        self::assertSame($tested, $plan->getTestedAt());
        self::assertSame(90, $plan->getEstimatedDurationDays());
        self::assertSame('75000.00', $plan->getEstimatedCost());
    }

    #[Test]
    public function exitTriggerCatalogueIsComplete(): void
    {
        self::assertSame(
            [
                'planned-renewal',
                'concentration-risk',
                'force-majeure',
                'breach',
                'insolvency',
            ],
            DoraExitPlan::EXIT_TRIGGERS,
        );
    }

    #[Test]
    public function rehearsalOverdueWhenNeverTested(): void
    {
        $plan = new DoraExitPlan();
        self::assertTrue($plan->isRehearsalOverdue());
    }

    #[Test]
    public function rehearsalOverdueWhenOlderThan12Months(): void
    {
        $now = new DateTimeImmutable('2026-05-25');
        $tested = new DateTimeImmutable('2025-01-01'); // ~16 months earlier
        $plan = (new DoraExitPlan())->setTestedAt($tested);

        self::assertTrue($plan->isRehearsalOverdue($now));
    }

    #[Test]
    public function rehearsalNotOverdueWhenRecent(): void
    {
        $now = new DateTimeImmutable('2026-05-25');
        $tested = new DateTimeImmutable('2026-01-01'); // 4 months earlier
        $plan = (new DoraExitPlan())->setTestedAt($tested);

        self::assertFalse($plan->isRehearsalOverdue($now));
    }

    #[Test]
    public function rehearsalNotOverdueAtExactlyTwelveMonthThreshold(): void
    {
        $now = new DateTimeImmutable('2026-05-25');
        // 12 months ago to the day — must NOT be flagged as overdue
        $tested = new DateTimeImmutable('2025-05-25');
        $plan = (new DoraExitPlan())->setTestedAt($tested);

        self::assertFalse($plan->isRehearsalOverdue($now));
    }
}
