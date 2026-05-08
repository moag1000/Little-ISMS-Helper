<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class WizardRunTest extends TestCase
{
    #[Test]
    public function testCanInstantiate(): void
    {
        $run = new WizardRun();
        $tenant = new Tenant();
        $user = new User();

        $run->setTenant($tenant)
            ->setStartedByUser($user)
            ->setStandardsAdopted(['iso27001', 'dora'])
            ->setMode('full')
            ->setStep('welcome')
            ->setStatus('in_progress')
            ->setInputs(['welcome' => ['acknowledged' => true]])
            ->setAffectedFunctions(['IT_OPERATIONS']);

        $this->assertSame($tenant, $run->getTenant());
        $this->assertSame($user, $run->getStartedByUser());
        $this->assertSame(['iso27001', 'dora'], $run->getStandardsAdopted());
        $this->assertSame('full', $run->getMode());
        $this->assertSame('welcome', $run->getStep());
        $this->assertSame('in_progress', $run->getStatus());
        $this->assertSame(['IT_OPERATIONS'], $run->getAffectedFunctions());
        $this->assertNotNull($run->getStartedAt());
        $this->assertNull($run->getCompletedAt());
        $this->assertNull($run->getErrorMessage());
        $this->assertNull($run->getTargetedTopics());
        $this->assertNull($run->getFindingReference());
        $this->assertNull($run->getGeneratedDocumentIds());
    }

    #[Test]
    public function testTenantScoping(): void
    {
        $run = new WizardRun();
        $tenant = new Tenant();
        $tenant->setName('ACME GmbH');

        $run->setTenant($tenant);

        $this->assertSame($tenant, $run->getTenant(),
            'WizardRun must round-trip the tenant FK');
        // tenant_id is exposed via the Tenant relation; the convenience
        // getTenantId() proxies into the relation so a null id (in-memory
        // instance) still round-trips safely.
        $this->assertNull($run->getTenantId());
    }

    #[Test]
    public function testCompletedAtTransition(): void
    {
        $run = new WizardRun();
        $this->assertNull($run->getCompletedAt());
        $this->assertSame('in_progress', $run->getStatus());

        $completedAt = new DateTimeImmutable('2026-05-08 12:34:56');
        $run->setStatus('completed')
            ->setCompletedAt($completedAt)
            ->setGeneratedDocumentIds([101, 102, 103]);

        $this->assertSame('completed', $run->getStatus());
        $this->assertSame($completedAt, $run->getCompletedAt());
        $this->assertSame([101, 102, 103], $run->getGeneratedDocumentIds());

        // Ensure the completedAt can be cleared (e.g. cancelled mid-run).
        $run->setCompletedAt(null);
        $this->assertNull($run->getCompletedAt());
    }
}
