<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Service\BackupService;
use App\Service\RestoreService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An organisation-scoped backup skipped the organisation record itself (it has no
 * tenant field), so every organisation setting — branding, TLP default, DPO data,
 * the settings JSON with the map settings — was lost on restore; only the full
 * backup carried them. This test runs the real backup + scoped restore against the
 * database: the settings changed after the backup come back, a second organisation
 * stays untouched, the existing record is updated in place (same id, no
 * optimistic-lock failure from the older lock version in the backup), and a
 * crafted backup cannot change the organisation's identity fields.
 */
#[Group('integration')]
final class OrganisationBackupSettingsRoundTripTest extends KernelTestCase
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
    public function organisationSettingsSurviveScopedBackupAndRestore(): void
    {
        $own = $this->tenant('own');
        $own->setTlpDefault('red')
            ->setDpoContactName('Dana DSB')
            ->setEmailFooterText('Footer Original')
            ->setSettings(['location_map' => ['peer_comparison' => 'anonymous']]);
        $foreign = $this->tenant('foreign');
        $foreign->setTlpDefault('green');
        $this->em->flush();
        $ownId = (int) $own->getId();
        $foreignId = (int) $foreign->getId();

        $backup = self::getContainer()->get(BackupService::class)->createBackup(false, false, false, $own);

        self::assertSame([$ownId], array_column($backup['data']['Tenant'] ?? [], 'id'));

        // A crafted backup with a recomputed seal must not change the organisation's
        // identity: code, SSO binding, active flag, holding flag, parent.
        $ownCode = $own->getCode();
        $backup['data']['Tenant'][0]['code'] = 'hijacked';
        $backup['data']['Tenant'][0]['azureTenantId'] = 'foreign-entra-id';
        $backup['data']['Tenant'][0]['isActive'] = false;
        $backup['data']['Tenant'][0]['isCorporateParent'] = true;
        $backup['data']['Tenant'][0]['parent_id'] = ['id' => $foreignId];
        $backup['metadata']['sha256'] = hash('sha256', (string) json_encode($backup['data']));

        // Settings drift after the backup (and the lock version moves on).
        $own->setTlpDefault('clear')
            ->setDpoContactName(null)
            ->setEmailFooterText('Footer Changed')
            ->setSettings(null);
        $foreign->setTlpDefault('amber');
        $this->em->flush();

        $result = self::getContainer()->get(RestoreService::class)->restoreFromBackup($backup, [], $own);

        self::assertTrue($result['success'], implode("\n", $result['warnings'] ?? []));

        $this->em->clear();
        $restored = $this->em->find(Tenant::class, $ownId);
        self::assertNotNull($restored, 'organisation record keeps its id');
        self::assertSame('red', $restored->getTlpDefault());
        self::assertSame('Dana DSB', $restored->getDpoContactName());
        self::assertSame('Footer Original', $restored->getEmailFooterText());
        self::assertSame(['location_map' => ['peer_comparison' => 'anonymous']], $restored->getSettings());
        self::assertSame($ownCode, $restored->getCode());
        self::assertNull($restored->getAzureTenantId());
        self::assertTrue($restored->isActive());
        self::assertFalse($restored->isCorporateParent());
        self::assertNull($restored->getParent());

        self::assertSame('amber', $this->em->find(Tenant::class, $foreignId)?->getTlpDefault(), 'foreign organisation untouched');
    }

    private function tenant(string $label): Tenant
    {
        $suffix = substr(uniqid(), -8);
        $tenant = (new Tenant())->setName('Backup ' . $label . ' ' . $suffix)->setCode('bkp_' . $label . '_' . $suffix);
        $tenant->setIsActive(true);
        $this->em->persist($tenant);

        return $tenant;
    }
}
