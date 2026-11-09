<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard;

use App\Entity\Tenant;
use App\Entity\ThreatLedPenetrationTest;
use App\Service\ComplianceWizard\RecordCompletenessCheck;
use App\Service\ComplianceWizardService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DORA Art. 26 (1): a TLPT at least every three years. A planned row is no
 * evidence (W9 — the check was `entity_presence` before); only a closed test
 * that ended within 36 months and carries its summary scores.
 */
#[Group('integration')]
final class TlptRecencyCheckTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    #[Test]
    public function onlyAClosedTestWithinThirtySixMonthsCounts(): void
    {
        $today = new DateTimeImmutable('2026-11-09');
        $tenant = $this->tenant();
        $this->tlpt($tenant, ThreatLedPenetrationTest::STATUS_PLANNED, null, null);
        $this->tlpt($tenant, ThreatLedPenetrationTest::STATUS_CLOSED, '2022-06-30', 'Old summary');
        $this->em->flush();

        $result = $this->check($tenant, $today);
        self::assertSame(0.0, $result['score'], 'planned + one older than 36 months');
        self::assertSame('wizard.gap.tlpt_not_recent', $result['gap']['title'] ?? null);

        $this->tlpt($tenant, ThreatLedPenetrationTest::STATUS_CLOSED, '2025-03-31', null);
        $this->em->flush();
        self::assertSame(0.0, $this->check($tenant, $today)['score'], 'closed recently but without summary report');

        $this->tlpt($tenant, ThreatLedPenetrationTest::STATUS_CLOSED, '2025-09-30', 'Attestation and summary');
        $this->em->flush();
        self::assertSame(50.0, $this->check($tenant, $today)['score'], 'one of two recent closed tests carries the report');
    }

    /** @return array<string, mixed> */
    private function check(Tenant $tenant, DateTimeImmutable $today): array
    {
        $definition = null;
        foreach (self::getContainer()->get(ComplianceWizardService::class)->getWizardDefinitions() as $wizard) {
            foreach ((array) ($wizard['categories'] ?? []) as $category) {
                foreach ((array) ($category['checks'] ?? []) as $check) {
                    if (is_array($check) && ($check['entity'] ?? null) === ThreatLedPenetrationTest::class) {
                        $definition = $check;
                    }
                }
            }
        }
        self::assertIsArray($definition, 'the DORA wizard has a TLPT check');
        self::assertSame('record_completeness', $definition['type']);

        return self::getContainer()->get(RecordCompletenessCheck::class)->check($definition, $tenant, $today);
    }

    private function tenant(): Tenant
    {
        $suffix = substr(uniqid(), -8);
        $tenant = (new Tenant())->setName('TLPT ' . $suffix)->setCode('tlpt_' . $suffix);
        $tenant->setIsActive(true);
        $this->em->persist($tenant);

        return $tenant;
    }

    private function tlpt(Tenant $tenant, string $status, ?string $end, ?string $summary): void
    {
        $test = (new ThreatLedPenetrationTest())
            ->setTenant($tenant)
            ->setEngagementNumber('T-' . uniqid())
            ->setTitle('TLPT')
            ->setStatus($status) // @phpstan-ignore lifecycle.directSetStatus (test fixture)
            ->setActualEndDate($end !== null ? new DateTimeImmutable($end) : null)
            ->setExecutiveSummary($summary);
        $this->em->persist($test);
    }
}
