<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Service\BackupService;
use App\Service\RestoreService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A backup of one organisation carries that organisation's own settings and
 * brings them back on restore, without touching its identity or other tenants.
 */
final class TenantScopedBackupRoundTripTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private Tenant $other;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('bk_', false);

        $this->tenant = (new Tenant())->setName('Backup ' . $uid)->setCode('bk_' . $uid)->setAzureTenantId('sso-' . $uid)
            ->setSettings(['location_map' => ['peer_mode' => 'named']]);
        $this->other = (new Tenant())->setName('Other ' . $uid)->setCode('other_' . $uid)
            ->setSettings(['location_map' => ['peer_mode' => 'off']]);
        $this->em->persist($this->tenant);
        $this->em->persist($this->other);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ([$this->tenant, $this->other] as $tenant) {
            try {
                $managed = $this->em->find(Tenant::class, $tenant->getId());
                if ($managed !== null) {
                    $this->em->remove($managed);
                    $this->em->flush();
                }
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function settingsOfTheOrganisationSurviveAScopedBackupAndRestore(): void
    {
        $backup = static::getContainer()->get(BackupService::class)->createBackup(false, false, false, $this->tenant);
        $tenantIds = array_map(static fn (array $row): int => (int) $row['id'], $backup['data']['Tenant'] ?? []);
        self::assertSame([$this->tenant->getId()], $tenantIds, 'only the organisation of the scope');

        // A crafted backup must not change who the organisation is.
        $backup = json_decode((string) json_encode($backup), true, flags: JSON_THROW_ON_ERROR);
        $backup['data']['Tenant'][0]['azureTenantId'] = 'sso-someone-else';
        $backup['metadata']['sha256'] = hash('sha256', (string) json_encode($backup['data']));

        $this->tenant->setSettings(['location_map' => ['peer_mode' => 'anonymous']]);
        $this->em->flush();

        static::getContainer()->get(RestoreService::class)->restoreFromBackup($backup, [], $this->tenant);

        $this->em->clear();
        $restored = $this->em->find(Tenant::class, $this->tenant->getId());
        self::assertSame('named', $restored->getSettings()['location_map']['peer_mode'] ?? null);
        self::assertSame($this->tenant->getAzureTenantId(), $restored->getAzureTenantId());
        self::assertSame('off', $this->em->find(Tenant::class, $this->other->getId())->getSettings()['location_map']['peer_mode'] ?? null);
    }

    #[Test]
    public function aGlobalBackupRunsThroughEntitiesWithABlockedFindAll(): void
    {
        // ComplianceMappingRepository::findAll() throws against tenant leaks.
        $backup = static::getContainer()->get(BackupService::class)->createBackup(false, false, false);

        self::assertArrayHasKey('ComplianceMapping', $backup['data']);
        self::assertContains($this->other->getId(), array_map(static fn (array $row): int => (int) $row['id'], $backup['data']['Tenant']));
    }
}
