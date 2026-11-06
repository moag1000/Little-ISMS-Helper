<?php

declare(strict_types=1);

namespace App\Tests\Repository\Notification;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Entity\Notification\NotificationRule;
use App\Entity\Tenant;
use App\Repository\Notification\NotificationDeliveryRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The digest command found its channels with `SELECT DISTINCT c FROM Delivery d JOIN d.channel c`,
 * which Doctrine rejects at runtime — digest mails were never sent. Runs the query against the DB.
 */
final class PendingDigestChannelsTest extends KernelTestCase
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
    public function returnsEachChannelWithPendingDigestsOnce(): void
    {
        $uid = substr(md5((string) microtime(true)), 0, 8);
        $tenant = (new Tenant())->setName('Digest ' . $uid)->setCode('dg_' . $uid);
        $rule = (new NotificationRule())->setTenant($tenant)->setName('r')->setEventType('risk.created');
        $digest = (new NotificationChannel())->setTenant($tenant)->setName('digest ' . $uid)
            ->setType(NotificationChannel::TYPE_EMAIL);
        $immediate = (new NotificationChannel())->setTenant($tenant)->setName('now ' . $uid)
            ->setType(NotificationChannel::TYPE_EMAIL);
        foreach ([$tenant, $rule, $digest, $immediate] as $entity) {
            $this->em->persist($entity);
        }
        foreach ([[$digest, NotificationDelivery::STATUS_PENDING_DIGEST], [$digest, NotificationDelivery::STATUS_PENDING_DIGEST],
            [$immediate, NotificationDelivery::STATUS_PENDING]] as [$channel, $status]) {
            $this->em->persist((new NotificationDelivery())->setTenant($tenant)->setRule($rule)
                ->setChannel($channel)->setStatus($status)->setAttemptedAt(new \DateTimeImmutable()));
        }
        $this->em->flush();

        $channels = self::getContainer()->get(NotificationDeliveryRepository::class)->findChannelsWithPendingDigests();
        $ids = array_map(static fn (NotificationChannel $c): ?int => $c->getId(), $channels);

        self::assertContains($digest->getId(), $ids);
        self::assertNotContains($immediate->getId(), $ids);
        self::assertSame(array_unique($ids), $ids);
    }
}
