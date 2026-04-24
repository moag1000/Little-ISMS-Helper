<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Command\LoadIndustryBaselinesCommand;
use App\Entity\AppliedBaseline;
use App\Entity\IndustryBaseline;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\IndustryBaselineApplier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\ArrayInput;

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
        $em = $container->get('doctrine.orm.entity_manager');

        // Idempotent seeding: CI runs against a fresh DB where baselines haven't
        // been loaded yet. The command is idempotent (create-or-update), so calling
        // it here is safe in environments where fixtures already ran.
        $repo = $em->getRepository(IndustryBaseline::class);
        if ($repo->findOneBy(['code' => 'BL-GENERIC-v1']) === null) {
            $command = $container->get(LoadIndustryBaselinesCommand::class);
            $command(new SymfonyStyle(new ArrayInput([]), new NullOutput()));
            $em->clear();
        }

        return [
            'applier' => $container->get(IndustryBaselineApplier::class),
            'em' => $em,
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
            self::assertSame('2.0', $applied->getBaselineVersion());
        } finally {
            $em->rollback();
        }
    }

    public function testApplyRecursivePropagatesToSubsidiaries(): void
    {
        ['applier' => $applier, 'em' => $em] = $this->bootServices();

        $em->beginTransaction();
        try {
            $holding = (new Tenant())
                ->setName('UT Holding ' . uniqid('', true))
                ->setCode('ut-holding-' . bin2hex(random_bytes(4)))
                ->setIsCorporateParent(true);
            $em->persist($holding);

            $sub1 = (new Tenant())
                ->setName('UT Tochter 1 ' . uniqid('', true))
                ->setCode('ut-sub1-' . bin2hex(random_bytes(4)));
            $holding->addSubsidiary($sub1);
            $em->persist($sub1);

            $sub2 = (new Tenant())
                ->setName('UT Tochter 2 ' . uniqid('', true))
                ->setCode('ut-sub2-' . bin2hex(random_bytes(4)));
            $holding->addSubsidiary($sub2);
            $em->persist($sub2);

            $grandchild = (new Tenant())
                ->setName('UT Enkel ' . uniqid('', true))
                ->setCode('ut-grand-' . bin2hex(random_bytes(4)));
            $sub1->addSubsidiary($grandchild);
            $em->persist($grandchild);
            $em->flush();

            $baseline = $em->getRepository(IndustryBaseline::class)->findOneBy(['code' => 'BL-GENERIC-v1']);
            self::assertNotNull($baseline);

            $results = $applier->applyRecursive($baseline, $holding);

            self::assertCount(4, $results, 'Holding + 2 subs + 1 grandchild');
            self::assertFalse($results[$holding->getCode()]['already_applied']);
            self::assertFalse($results[$sub1->getCode()]['already_applied']);
            self::assertFalse($results[$sub2->getCode()]['already_applied']);
            self::assertFalse($results[$grandchild->getCode()]['already_applied']);
            self::assertGreaterThan(0, $results[$sub1->getCode()]['risks_created']);

            foreach ([$holding, $sub1, $sub2, $grandchild] as $t) {
                $record = $em->getRepository(AppliedBaseline::class)
                    ->findOneBy(['tenant' => $t, 'baselineCode' => 'BL-GENERIC-v1']);
                self::assertNotNull($record, 'Every tenant in subtree gets AppliedBaseline record');
            }

            $secondRun = $applier->applyRecursive($baseline, $holding);
            self::assertTrue($secondRun[$holding->getCode()]['already_applied']);
            self::assertTrue($secondRun[$grandchild->getCode()]['already_applied']);
        } finally {
            $em->rollback();
        }
    }

    public function testInheritedBaselinesExcludeDirectlyApplied(): void
    {
        ['em' => $em] = $this->bootServices();
        $applier = self::getContainer()->get(IndustryBaselineApplier::class);
        $appliedRepo = $em->getRepository(AppliedBaseline::class);

        $em->beginTransaction();
        try {
            $holding = (new Tenant())
                ->setName('UT Holding ' . uniqid('', true))
                ->setCode('ut-holding-' . bin2hex(random_bytes(4)));
            $em->persist($holding);

            $sub = (new Tenant())
                ->setName('UT Tochter ' . uniqid('', true))
                ->setCode('ut-sub-' . bin2hex(random_bytes(4)));
            $holding->addSubsidiary($sub);
            $em->persist($sub);
            $em->flush();

            $generic = $em->getRepository(IndustryBaseline::class)->findOneBy(['code' => 'BL-GENERIC-v1']);
            $production = $em->getRepository(IndustryBaseline::class)->findOneBy(['code' => 'BL-PRODUCTION-v1']);
            self::assertNotNull($generic);
            self::assertNotNull($production);

            // Holding applies both; child applies Generic directly as well
            $applier->apply($generic, $holding);
            $applier->apply($production, $holding);
            $applier->apply($generic, $sub);

            $inherited = $appliedRepo->findInheritedByTenant($sub);

            // Generic is direct on sub — must not appear as inherited
            self::assertArrayNotHasKey('BL-GENERIC-v1', $inherited);
            // Production only on holding — must appear as inherited
            self::assertArrayHasKey('BL-PRODUCTION-v1', $inherited);
            self::assertSame($holding->getId(), $inherited['BL-PRODUCTION-v1']->getTenant()->getId());

            // Holding itself has no ancestors, so no inheritance
            self::assertSame([], $appliedRepo->findInheritedByTenant($holding));
        } finally {
            $em->rollback();
        }
    }
}
