<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AppliedBaseline;
use App\Entity\IndustryBaseline;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\IndustryBaselineApplier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Sanity check that the seeded industry baselines apply cleanly to a
 * fresh tenant and record the action in applied_baseline.
 */
final class IndustryBaselineApplierTest extends KernelTestCase
{
    private function bootServices(): array
    {
        self::bootKernel();
        $container = self::getContainer();
        return [
            'applier' => $container->get(IndustryBaselineApplier::class),
            'em' => $container->get('doctrine.orm.entity_manager'),
        ];
    }

    public function testSeededBaselinesExist(): void
    {
        ['em' => $em] = $this->bootServices();
        $repo = $em->getRepository(IndustryBaseline::class);
        $codes = array_map(static fn(IndustryBaseline $b) => $b->getCode(), $repo->findAll());

        self::assertContains('BL-GENERIC-v1', $codes, 'Generic starter baseline should be seeded');
        self::assertContains('BL-PRODUCTION-v1', $codes, 'Production baseline should be seeded');
        self::assertContains('BL-FINANCE-v1', $codes, 'Finance baseline should be seeded');
        self::assertContains('BL-KRITIS-HEALTH-v1', $codes, 'KRITIS-Health baseline should be seeded');
    }

    public function testApplyIsIdempotent(): void
    {
        ['applier' => $applier, 'em' => $em] = $this->bootServices();

        $em->beginTransaction();
        try {
            $tenant = (new Tenant())
                ->setName('UT Tenant ' . uniqid('', true))
                ->setCode('ut-tenant-' . bin2hex(random_bytes(4)));
            $em->persist($tenant);

            $user = (new User())
                ->setEmail('ut-user-' . bin2hex(random_bytes(4)) . '@example.test')
                ->setTenant($tenant);
            $user->setPassword('x');
            if (method_exists($user, 'setFirstName')) {
                $user->setFirstName('UT');
            }
            if (method_exists($user, 'setLastName')) {
                $user->setLastName('User');
            }
            $em->persist($user);
            $em->flush();

            $baseline = $em->getRepository(IndustryBaseline::class)->findOneBy(['code' => 'BL-GENERIC-v1']);
            self::assertNotNull($baseline);

            $first = $applier->apply($baseline, $tenant, $user);
            self::assertFalse($first['already_applied']);
            self::assertGreaterThan(0, $first['risks_created']);
            self::assertGreaterThan(0, $first['assets_created']);

            $second = $applier->apply($baseline, $tenant, $user);
            self::assertTrue($second['already_applied']);
            self::assertSame(0, $second['risks_created']);
            self::assertSame(0, $second['assets_created']);

            $applied = $em->getRepository(AppliedBaseline::class)
                ->findOneBy(['tenant' => $tenant, 'baselineCode' => 'BL-GENERIC-v1']);
            self::assertNotNull($applied);
            self::assertSame('1.0', $applied->getBaselineVersion());
        } finally {
            $em->rollback();
        }
    }
}
