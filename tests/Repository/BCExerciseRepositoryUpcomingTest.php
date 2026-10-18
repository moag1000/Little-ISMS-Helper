<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\BCExercise;
use App\Entity\Tenant;
use App\Repository\BCExerciseRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * findUpcoming() used to return every tenant's upcoming exercises; outside
 * a web request (no tenant Doctrine filter) the BSI calendar and the
 * BC-exercise index relied on nothing else.
 */
final class BCExerciseRepositoryUpcomingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            try {
                $this->em->remove($this->em->getReference($entity::class, $entity->getId()));
            } catch (\Throwable) {
            }
        }
        try {
            $this->em->flush();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    #[Test]
    public function upcomingIsScopedToTheGivenTenant(): void
    {
        $own = $this->tenant('own');
        $foreign = $this->tenant('foreign');
        $mine = $this->exercise($own);
        $theirs = $this->exercise($foreign);

        $repository = static::getContainer()->get(BCExerciseRepository::class);
        $ids = array_map(static fn (BCExercise $e): ?int => $e->getId(), $repository->findUpcoming($own));

        self::assertContains($mine->getId(), $ids);
        self::assertNotContains($theirs->getId(), $ids);

        // null = all tenants (only for a SUPER_ADMIN without tenant context).
        $all = array_map(static fn (BCExercise $e): ?int => $e->getId(), $repository->findUpcoming(null));
        self::assertContains($theirs->getId(), $all);
    }

    private function tenant(string $label): Tenant
    {
        $uid = uniqid($label, true);
        $tenant = (new Tenant())->setName('Upcoming ' . $uid)->setCode('up_' . substr($uid, -8));
        $this->em->persist($tenant);
        $this->em->flush();
        $this->created[] = $tenant;

        return $tenant;
    }

    private function exercise(Tenant $tenant): BCExercise
    {
        $exercise = new BCExercise();
        $exercise->setName('Upcoming ' . uniqid());
        $exercise->setExerciseType('tabletop');
        $exercise->setScope('scope');
        $exercise->setObjectives('objective');
        $exercise->setParticipants('Alice');
        $exercise->setFacilitator('Bob');
        $exercise->setStatus('planned');
        $exercise->setExerciseDate(new \DateTime('+5 days'));
        $exercise->setTenant($tenant);
        $this->em->persist($exercise);
        $this->em->flush();
        $this->created[] = $exercise;

        return $exercise;
    }
}
