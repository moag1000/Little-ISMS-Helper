<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Service\RiskReviewService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Closed risks are not periodically reviewed (RiskStatus::requiresPeriodicReview()):
 * neither the "never reviewed" figure nor the bulk scheduling may touch them.
 */
final class RiskReviewServiceClosedRisksTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RiskReviewService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(RiskReviewService::class);
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
    public function neverReviewedAndBulkScheduleSkipClosedRisks(): void
    {
        $uid = substr(uniqid('', true), -8);
        $tenant = (new Tenant())->setName('Rev ' . $uid)->setCode('rev_' . $uid);
        $this->em->persist($tenant);
        $open = $this->risk($tenant, 'open', RiskStatus::Identified);
        $accepted = $this->risk($tenant, 'accepted', RiskStatus::Accepted);
        $closed = $this->risk($tenant, 'closed', RiskStatus::Closed);
        $this->em->flush();

        self::assertSame(2, $this->service->getReviewStatistics($tenant)['never_reviewed']);

        self::assertSame(2, $this->service->bulkScheduleReviews($tenant));
        $this->em->refresh($closed);
        self::assertNull($closed->getReviewDate(), 'closed risk gets no review date');
        self::assertNotNull($open->getReviewDate());
        self::assertNotNull($accepted->getReviewDate());
        self::assertSame(0, $this->service->getReviewStatistics($tenant)['never_reviewed']);
    }

    private function risk(Tenant $tenant, string $title, RiskStatus $status): Risk
    {
        $risk = new Risk();
        $risk->setTenant($tenant);
        $risk->setTitle($title);
        $risk->setDescription('d');
        $risk->setCategory('security');
        $risk->setProbability(2);
        $risk->setImpact(2);
        $risk->setResidualProbability(1);
        $risk->setResidualImpact(1);
        $risk->setTreatmentStrategy(TreatmentStrategy::Mitigate);
        $risk->setStatus($status);
        $this->em->persist($risk);

        return $risk;
    }
}
