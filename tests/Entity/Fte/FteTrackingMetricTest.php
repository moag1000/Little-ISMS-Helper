<?php

declare(strict_types=1);

namespace App\Tests\Entity\Fte;

use App\Entity\Fte\FteTrackingMetric;
use App\Entity\Tenant;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FteTrackingMetricTest extends TestCase
{
    #[Test]
    public function itHasCorrectSourceConstants(): void
    {
        $this->assertSame('bulk_import', FteTrackingMetric::SOURCE_BULK_IMPORT);
        $this->assertSame('sso_jit', FteTrackingMetric::SOURCE_SSO_JIT);
        $this->assertSame('evidence_reuse', FteTrackingMetric::SOURCE_EVIDENCE_REUSE);
        $this->assertSame('manual_calibration', FteTrackingMetric::SOURCE_MANUAL_CALIBRATION);
        $this->assertSame('workflow_automation', FteTrackingMetric::SOURCE_WORKFLOW_AUTOMATION);
        $this->assertSame('notification_automation', FteTrackingMetric::SOURCE_NOTIFICATION_AUTOMATION);
    }

    #[Test]
    public function itHasCorrectPeriodConstants(): void
    {
        $this->assertSame('realtime', FteTrackingMetric::PERIOD_REALTIME);
        $this->assertSame('daily', FteTrackingMetric::PERIOD_DAILY);
        $this->assertSame('monthly', FteTrackingMetric::PERIOD_MONTHLY);
    }

    #[Test]
    public function itSetsDefaultsOnConstruct(): void
    {
        $metric = new FteTrackingMetric();

        $this->assertSame(FteTrackingMetric::PERIOD_REALTIME, $metric->getPeriod());
        $this->assertInstanceOf(DateTimeImmutable::class, $metric->getRecordedAt());
        $this->assertNull($metric->getEntityId());
        $this->assertNull($metric->getMetadata());
    }

    #[Test]
    public function itMutatesAllFields(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $now = new DateTimeImmutable();

        $metric = new FteTrackingMetric();
        $metric->setTenant($tenant);
        $metric->setSource(FteTrackingMetric::SOURCE_SSO_JIT);
        $metric->setEntityType('User');
        $metric->setEntityId(42);
        $metric->setManualMinutesEstimate(20);
        $metric->setActualMinutesEstimate(0);
        $metric->setSavingsMinutes(20);
        $metric->setRecordedAt($now);
        $metric->setPeriod(FteTrackingMetric::PERIOD_DAILY);
        $metric->setMetadata(['foo' => 'bar']);

        $this->assertSame($tenant, $metric->getTenant());
        $this->assertSame(FteTrackingMetric::SOURCE_SSO_JIT, $metric->getSource());
        $this->assertSame('User', $metric->getEntityType());
        $this->assertSame(42, $metric->getEntityId());
        $this->assertSame(20, $metric->getManualMinutesEstimate());
        $this->assertSame(0, $metric->getActualMinutesEstimate());
        $this->assertSame(20, $metric->getSavingsMinutes());
        $this->assertSame($now, $metric->getRecordedAt());
        $this->assertSame(FteTrackingMetric::PERIOD_DAILY, $metric->getPeriod());
        $this->assertSame(['foo' => 'bar'], $metric->getMetadata());
    }
}
