<?php

declare(strict_types=1);

namespace App\Tests\Service\Group;

use App\Command\LoadIndustryBaselinesCommand;
use App\Entity\IndustryBaseline;
use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Entity\User;
use App\Service\IndustryBaselineApplier;
use App\Service\PolicyWizard\KonzernPushDownService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Holding actions that write into subsidiaries run inside a request, where the
 * Doctrine tenant filter is armed with the holding. Their lookups of the
 * subsidiaries' own rows used to come back empty: the push-down never flagged
 * a subsidiary, and applying a baseline to the subtree re-created the preset
 * risks on every click. Real database, filter armed as in a request.
 */
final class HoldingWritePathsDbTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Tenant $holding;
    private Tenant $subsidiary;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();

        $uid = bin2hex(random_bytes(4));
        $this->holding = (new Tenant())->setName('Holding ' . $uid)->setCode('hwp-h-' . $uid)->setIsCorporateParent(true);
        $this->subsidiary = (new Tenant())->setName('Tochter ' . $uid)->setCode('hwp-s-' . $uid);
        $this->holding->addSubsidiary($this->subsidiary);
        $this->em->persist($this->holding);
        $this->em->persist($this->subsidiary);
        $this->em->flush();

        // As in a request of a holding user.
        self::getContainer()->get(TenantContext::class)->setCurrentTenant($this->holding);
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    #[Test]
    public function pushDownFlagsTheSubsidiaryUnderTheArmedFilter(): void
    {
        $user = (new User())->setEmail('hwp-' . bin2hex(random_bytes(4)) . '@example.test')->setTenant($this->subsidiary);
        $user->setPassword('x');
        $user->setFirstName('Hold')->setLastName('Ing');
        $this->em->persist($user);
        $setting = (new TenantPolicySetting())
            ->setTenant($this->subsidiary)
            ->setKey('crypto.minimum_key_length')
            ->setValue(192)
            ->setUpdatedByUser($user);
        $this->em->persist($setting);
        $this->em->flush();

        $result = self::getContainer()->get(KonzernPushDownService::class)
            ->propagate($this->holding, 'crypto.minimum_key_length', 256);

        self::assertSame([$this->subsidiary->getId()], $result['affected_subsidiaries']);
        self::assertTrue($setting->getValue()['_meta']['settings_drift_detected'] ?? false);
    }

    #[Test]
    public function baselineOnTheSubtreeIsAppliedOnceToEachOrganisation(): void
    {
        $container = self::getContainer();
        $baselines = $this->em->getRepository(IndustryBaseline::class);
        if ($baselines->findOneBy(['code' => 'BL-GENERIC-v1']) === null) {
            $container->get(LoadIndustryBaselinesCommand::class)(new SymfonyStyle(new ArrayInput([]), new NullOutput()));
        }
        $baseline = $baselines->findOneBy(['code' => 'BL-GENERIC-v1']);
        self::assertInstanceOf(IndustryBaseline::class, $baseline);
        $applier = $container->get(IndustryBaselineApplier::class);

        $first = $applier->applyRecursive($baseline, $this->holding);
        $risksAfterFirst = $this->countRisks($this->subsidiary);
        self::assertGreaterThan(0, $risksAfterFirst);

        $second = $applier->applyRecursive($baseline, $this->holding);

        self::assertFalse($first[(string) $this->subsidiary->getCode()]['already_applied']);
        self::assertTrue($second[(string) $this->subsidiary->getCode()]['already_applied']);
        self::assertSame($risksAfterFirst, $this->countRisks($this->subsidiary), 'no duplicate preset risks');
        self::assertSame($this->holding->getId(), $container->get(TenantContext::class)->getCurrentTenant()?->getId());
    }

    private function countRisks(Tenant $tenant): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM risk WHERE tenant_id = ?',
            [$tenant->getId()],
        );
    }
}
