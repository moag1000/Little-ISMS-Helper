<?php

declare(strict_types=1);

namespace App\Tests\Relation;

use App\Entity\Asset;
use App\Entity\BusinessProcess;
use App\Entity\Control;
use App\Entity\Risk;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the core ISMS relation chain survives a round-trip through the
 * database: BusinessProcess → Asset → Risk → Control.
 *
 * Why this needs its own test:
 *   Half of these associations are inverse sides, and Doctrine only writes the
 *   OWNING side. An adder on an inverse side that forgets to sync its owning
 *   counterpart produces the worst kind of defect in a compliance tool — the
 *   UI reports success, the link is visible until the page is reloaded, and
 *   then it is silently gone. No unit test on the entity catches that, because
 *   in-memory collections look correct either way.
 *
 * The clear() below is the whole point: it forces every assertion to read from
 * the database rather than from the identity map.
 *
 * Verified owning sides (Doctrine metadata, 2026-08-17):
 *   BusinessProcess.supportingAssets   owning
 *   BusinessProcess.identifiedRisks    owning
 *   Asset.risks                        inverse → synced via Risk.asset
 *   Asset.protectingControls           inverse → synced via Control.protectedAssets
 *   Risk.asset                         owning
 *   Risk.controls                      inverse → synced via Control.risks
 *   Control.risks                      owning
 *   Control.protectedAssets            owning
 */
final class CoreEntityChainPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function theProcessAssetRiskControlChainSurvivesAReload(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Chain Test Tenant');
        $tenant->setCode('chain-test');
        $this->em->persist($tenant);

        $process = new BusinessProcess();
        $process->setName('Auftragsabwicklung');
        $process->setCriticality('high');
        $process->setRto(4);
        $process->setRpo(1);
        $process->setMtpd(24);
        $process->setTenant($tenant);

        $asset = new Asset();
        $asset->setName('ERP-System');
        $asset->setAssetType('software');
        $asset->setConfidentialityValue(3);
        $asset->setIntegrityValue(3);
        $asset->setAvailabilityValue(4);
        $asset->setTenant($tenant);

        $risk = new Risk();
        $risk->setTitle('Ausfall des ERP-Systems');
        $risk->setCategory('availability');
        $risk->setDescription('Laengerer Ausfall blockiert die Auftragsabwicklung.');
        $risk->setProbability(3);
        $risk->setImpact(4);
        $risk->setTenant($tenant);

        $control = new Control();
        $control->setControlId('A.8.14');
        $control->setName('Redundante Auslegung');
        $control->setDescription('Redundanz der informationsverarbeitenden Einrichtungen.');
        $control->setCategory('technological');
        $control->setApplicable(true);
        $control->setTenant($tenant);

        // Link the chain the way application code does.
        $process->addSupportingAsset($asset);
        $process->addIdentifiedRisk($risk);
        $risk->setAsset($asset);
        $risk->addControl($control);
        $control->addProtectedAsset($asset);

        foreach ([$process, $asset, $risk, $control] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $ids = [
            'process' => $process->getId(),
            'asset' => $asset->getId(),
            'risk' => $risk->getId(),
            'control' => $control->getId(),
        ];

        // Drop the identity map: everything below now comes from the database.
        $this->em->clear();

        try {
            $process = $this->em->find(BusinessProcess::class, $ids['process']);
            $asset = $this->em->find(Asset::class, $ids['asset']);
            $risk = $this->em->find(Risk::class, $ids['risk']);
            $control = $this->em->find(Control::class, $ids['control']);

            self::assertNotNull($process);
            self::assertNotNull($asset);
            self::assertNotNull($risk);
            self::assertNotNull($control);

            // Forward direction: process → asset → risk → control
            self::assertTrue(
                $process->getSupportingAssets()->contains($asset),
                'BusinessProcess must still support the asset after reload',
            );
            self::assertTrue(
                $process->getIdentifiedRisks()->contains($risk),
                'BusinessProcess must still carry the identified risk after reload',
            );
            self::assertSame(
                $asset->getId(),
                $risk->getAsset()?->getId(),
                'Risk must still point at its asset after reload',
            );
            self::assertTrue(
                $risk->getControls()->contains($control),
                'Risk must still be mitigated by the control after reload — '
                . 'Risk.controls is the INVERSE side, so this only holds if '
                . 'addControl() synced Control.risks (the owning side)',
            );

            // Reverse direction: the inverse collections must resolve too.
            self::assertTrue(
                $asset->getRisks()->contains($risk),
                'Asset.risks (inverse) must resolve back to the risk',
            );
            self::assertTrue(
                $control->getProtectedAssets()->contains($asset),
                'Control must still protect the asset after reload',
            );
            self::assertTrue(
                $control->getRisks()->contains($risk),
                'Control.risks (owning) must contain the risk after reload',
            );
        } finally {
            foreach (['control', 'risk', 'asset', 'process'] as $key) {
                $class = match ($key) {
                    'control' => Control::class,
                    'risk' => Risk::class,
                    'asset' => Asset::class,
                    'process' => BusinessProcess::class,
                };
                $entity = $this->em->find($class, $ids[$key]);
                if ($entity !== null) {
                    $this->em->remove($entity);
                }
            }
            $this->em->flush();

            $tenantRow = $this->em->getRepository(Tenant::class)->findOneBy(['code' => 'chain-test']);
            if ($tenantRow !== null) {
                $this->em->remove($tenantRow);
                $this->em->flush();
            }
        }
    }
}
