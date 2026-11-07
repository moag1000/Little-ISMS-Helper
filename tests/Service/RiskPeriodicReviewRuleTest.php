<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RiskStatus;
use App\Repository\RiskRepository;
use App\Service\Deadline\ObligationInbox;
use App\Service\RiskReviewService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * RiskStatus::requiresPeriodicReview() is the only review rule (closed risks
 * are never review-due). Real DB: a closed and an open risk, both with a
 * passed (or upcoming) review date — every review-due path returns the open
 * one only.
 */
#[Group('integration')]
final class RiskPeriodicReviewRuleTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private Risk $openOverdue;
    private Risk $closedOverdue;
    private Risk $closedUpcoming;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();

        $uid = uniqid('', true);
        $this->tenant = (new Tenant())->setName('Review ' . $uid)->setCode('rev_' . substr($uid, -10));
        $this->em->persist($this->tenant);
        $owner = (new User())->setEmail('owner_' . $uid . '@example.com')->setFirstName('O')->setLastName('W')
            ->setPassword('x')->setRoles(['ROLE_USER'])->setTenant($this->tenant)->setIsActive(true);
        $this->em->persist($owner);

        $this->openOverdue = $this->risk('Open overdue ' . $uid, RiskStatus::Accepted, new DateTime('-10 days'), $owner);
        $this->closedOverdue = $this->risk('Closed overdue ' . $uid, RiskStatus::Closed, new DateTime('-10 days'), $owner);
        $this->closedUpcoming = $this->risk('Closed upcoming ' . $uid, RiskStatus::Closed, new DateTime('+5 days'), $owner);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    #[Test]
    public function repositoryDueForReviewSkipsClosedRisks(): void
    {
        $due = self::getContainer()->get(RiskRepository::class)->findDueForReview($this->tenant);

        self::assertSame([$this->openOverdue->getId()], $this->ids($due));
    }

    #[Test]
    public function riskReviewServiceSkipsClosedRisks(): void
    {
        $service = self::getContainer()->get(RiskReviewService::class);

        self::assertSame([$this->openOverdue->getId()], $this->ids($service->getOverdueReviews($this->tenant)));
        self::assertSame([], $this->ids($service->getUpcomingReviews($this->tenant)));
    }

    #[Test]
    public function reminderInboxListsNoClosedRiskAsReviewDue(): void
    {
        // My Day, the digest and the urgent mail all read this inbox.
        $inbox = self::getContainer()->get(ObligationInbox::class);
        $titles = [];
        foreach ($inbox->byRecipient($this->tenant) as ['items' => $items]) {
            foreach ($items as $obligation) {
                if ($obligation->source->slug() === 'risk_review') {
                    $titles[] = $obligation->title;
                }
            }
        }

        self::assertContains($this->openOverdue->getTitle(), $titles);
        self::assertNotContains($this->closedOverdue->getTitle(), $titles);
    }

    /**
     * @param iterable<Risk> $risks
     * @return list<int|null>
     */
    private function ids(iterable $risks): array
    {
        $ids = [];
        foreach ($risks as $risk) {
            $ids[] = $risk->getId();
        }

        return $ids;
    }

    private function risk(string $title, RiskStatus $status, DateTime $reviewDate, User $owner): Risk
    {
        $risk = new Risk();
        $risk->setTenant($this->tenant);
        $risk->setTitle($title);
        $risk->setDescription('Review rule fixture');
        $risk->setCategory('security');
        $risk->setProbability(2);
        $risk->setImpact(2);
        $risk->setResidualProbability(1);
        $risk->setResidualImpact(1);
        $risk->setTreatmentStrategy(\App\Enum\TreatmentStrategy::Accept);
        $risk->setStatus($status);
        $risk->setReviewDate($reviewDate);
        $risk->setRiskOwner($owner);
        $this->em->persist($risk);

        return $risk;
    }
}
