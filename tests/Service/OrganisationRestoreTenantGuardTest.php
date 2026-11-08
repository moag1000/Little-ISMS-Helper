<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Asset;
use App\Entity\AuditLog;
use App\Entity\Role;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\BackupService;
use App\Service\RestoreService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An uploaded organisation backup is untrusted: ids, tenant_id values and the
 * SHA256 seal can be written by hand. The restore used to update every row it
 * found by id (moving another organisation's record into the own one), restored
 * every row without tenant_id (roles, permissions, MFA tokens, system settings)
 * and took User.roles as they came — a tenant admin could make themselves super
 * admin. This test runs the real backup + scoped restore against the database
 * with such a crafted file. See RestoreTenantGuard.
 */
#[Group('integration')]
final class OrganisationRestoreTenantGuardTest extends KernelTestCase
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
    public function craftedOrganisationBackupCannotReachOtherOrganisationsOrInstanceData(): void
    {
        $own = $this->tenant('own');
        $foreign = $this->tenant('foreign');
        $ownAsset = $this->asset('Own ERP', $own);
        $foreignAsset = $this->asset('Foreign ERP', $foreign);
        $this->em->flush();
        $ownId = (int) $own->getId();
        $foreignId = (int) $foreign->getId();
        $foreignAssetId = (int) $foreignAsset->getId();

        $backup = self::getContainer()->get(BackupService::class)->createBackup(false, false, false, $own);
        self::assertNotEmpty($backup['data']['Asset'] ?? [], 'own asset is in the organisation backup');

        // 1. A row of the own organisation that carries the id of a foreign record.
        $hijack = $backup['data']['Asset'][0];
        $hijack['id'] = $foreignAssetId;
        $hijack['name'] = 'hijacked';
        $hijack['tenant_id'] = ['id' => $ownId];
        $backup['data']['Asset'][] = $hijack;

        // 2. A tenant row without tenant_id and an instance row (Role).
        $orphan = $backup['data']['Asset'][0];
        unset($orphan['id'], $orphan['tenant_id']);
        $orphan['name'] = 'orphan without organisation';
        $backup['data']['Asset'][] = $orphan;
        $roleName = 'ROLE_CRAFTED_' . strtoupper(substr(uniqid(), -6));
        $backup['data']['Role'] = [['name' => $roleName, 'description' => 'crafted', 'isSystemRole' => true]];

        // 3. A new user of the own organisation that asks for super admin.
        $email = 'crafted-' . substr(uniqid(), -8) . '@example.test';
        $backup['data']['User'] = [[
            'email' => $email,
            'firstName' => 'Crafted',
            'lastName' => 'User',
            'roles' => ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'],
            'tenant_id' => ['id' => $ownId],
        ]];

        $backup['metadata']['sha256'] = hash('sha256', (string) json_encode($backup['data']));

        $result = self::getContainer()->get(RestoreService::class)
            ->restoreFromBackup($backup, ['best_effort' => true], $own);

        $this->em->clear();

        $foreignAfter = $this->em->find(Asset::class, $foreignAssetId);
        self::assertNotNull($foreignAfter, 'foreign record is not deleted');
        self::assertSame('Foreign ERP', $foreignAfter->getName(), 'foreign record is not overwritten');
        self::assertSame($foreignId, $foreignAfter->getTenant()?->getId(), 'foreign record stays in its organisation');

        self::assertNull(
            $this->em->getRepository(Asset::class)->findOneBy(['name' => 'orphan without organisation']),
            'a tenant row without tenant_id is not restored',
        );
        self::assertNull(
            $this->em->getRepository(Role::class)->findOneBy(['name' => $roleName]),
            'instance data (roles) is not restored by an organisation restore',
        );

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($user !== null) {
            self::assertNotContains('ROLE_SUPER_ADMIN', $user->getRoles(), 'super admin is never granted');
        }

        self::assertNotEmpty(array_filter(
            $result['warnings'] ?? [],
            static fn (string $w): bool => str_contains($w, 'outside the organisations of this restore'),
        ), 'the skipped foreign row is reported');
        self::assertNotNull($ownAsset->getId());
    }

    /**
     * Old backups carry lists that P5 merged (second AVV flag, subcontractor JSON, realized
     * risks, plan controls). They are written by plain SQL, so a row carrying a foreign id
     * must not reach the foreign record either.
     */
    #[Test]
    public function mergedListsOfOldBackupsStayInsideTheOrganisation(): void
    {
        $own = $this->tenant('own');
        $foreign = $this->tenant('foreign');
        $ownSupplier = $this->supplier('Own Cloud', $own);
        $foreignSupplier = $this->supplier('Foreign Cloud', $foreign);
        $this->em->flush();
        $ownSupplierId = (int) $ownSupplier->getId();
        $foreignSupplierId = (int) $foreignSupplier->getId();

        $backup = self::getContainer()->get(BackupService::class)->createBackup(false, false, false, $own);
        $rows = $backup['data']['Supplier'] ?? [];
        self::assertNotEmpty($rows, 'own supplier is in the organisation backup');
        $oldFields = [
            'gdprAvContractSigned' => true,
            'gdprAvContractDate' => '2026-01-15',
            'subcontractorChain' => [['name' => 'Sub GmbH', 'country' => 'DE']],
        ];
        foreach ($rows as $i => $row) {
            if (($row['id'] ?? null) === $ownSupplierId) {
                $rows[$i] = $oldFields + $row;
            }
        }
        $crafted = $oldFields + $rows[0];
        $crafted['id'] = $foreignSupplierId;
        $crafted['subcontractorChain'] = [['name' => 'Injected Ltd']];
        $rows[] = $crafted;
        $backup['data']['Supplier'] = $rows;
        $backup['metadata']['sha256'] = hash('sha256', (string) json_encode($backup['data']));

        self::getContainer()->get(RestoreService::class)
            ->restoreFromBackup($backup, ['best_effort' => true], $own);
        $this->em->clear();

        $connection = $this->em->getConnection();
        $countRows = static fn (int $supplierId): int => (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM dora_subcontractor WHERE parent_supplier_id = ?',
            [$supplierId],
        );
        self::assertSame(0, $countRows($foreignSupplierId), 'no subcontractor rows on the foreign supplier');
        self::assertFalse(
            $this->em->find(Supplier::class, $foreignSupplierId)?->isHasDPA() ?? true,
            'the foreign AVV flag is not set',
        );
        self::assertGreaterThan(0, $countRows($ownSupplierId), 'the own old chain becomes rows');
        self::assertTrue($this->em->find(Supplier::class, $ownSupplierId)?->isHasDPA() ?? false, 'the own old AVV flag folds in');
    }

    /**
     * S2 (third assessment): a crafted file granted ROLE_ALLOWED_TO_SWITCH (then
     * ?_switch_user=<super admin>), carried password hashes and SSO subjects, moved the
     * organisation under a holding and added audit-log rows. None of it may arrive.
     */
    #[Test]
    public function craftedBackupCannotGrantInstanceRightsCredentialsOrEvidence(): void
    {
        $own = $this->tenant('own');
        $holding = $this->tenant('holding');
        $colleague = (new User())->setEmail('colleague-' . substr(uniqid(), -8) . '@example.test')
            ->setFirstName('Live')->setLastName('Colleague')->setRoles(['ROLE_ADMIN'])
            ->setPassword('live-hash')->setTenant($own);
        $this->em->persist($colleague);
        $privilegedRole = $this->em->getRepository(Role::class)->findOneBy(['name' => 'ROLE_GROUP_CISO'])
            ?? (new Role())->setName('ROLE_GROUP_CISO')->setDescription('seeded')->setIsSystemRole(true);
        $this->em->persist($privilegedRole);
        $this->em->flush();
        $ownId = (int) $own->getId();
        $holdingId = (int) $holding->getId();
        $colleagueId = (int) $colleague->getId();

        $backup = self::getContainer()->get(BackupService::class)->createBackup(false, false, false, $own);

        foreach ($backup['data']['Tenant'] ?? [] as $i => $row) {
            if (($row['id'] ?? null) === $ownId) {
                $backup['data']['Tenant'][$i] = [
                    'parent_id' => ['id' => $holdingId],
                    'isCorporateParent' => true,
                    'isActive' => false,
                    'apiRateLimitPerMinute' => 1000000,
                ] + $row;
            }
        }
        $users = [];
        foreach ($backup['data']['User'] ?? [] as $row) {
            if (($row['id'] ?? null) === $colleagueId) {
                $row['password'] = 'forged-hash';
                $row['ssoExternalId'] = 'forged-subject';
                $row['roles'] = ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH'];
            }
            $users[] = $row;
        }
        $email = 'switcher-' . substr(uniqid(), -8) . '@example.test';
        $users[] = [
            'email' => $email,
            'firstName' => 'Crafted',
            'lastName' => 'Switcher',
            'roles' => ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH', 'role_group_ciso '],
            'password' => 'forged-hash',
            'tenant_id' => ['id' => $ownId],
            'customRoles_ids' => [(int) $privilegedRole->getId()],
        ];
        $backup['data']['User'] = $users;
        $backup['data']['AuditLog'] = [[
            'entityType' => 'Risk',
            'entityId' => 1,
            'action' => 'approve',
            'userName' => 'leitung@example.test',
            'description' => 'forged evidence',
            'createdAt' => '2026-01-01 10:00:00',
            'tenant_id' => ['id' => $ownId],
        ]];
        $backup['metadata']['sha256'] = hash('sha256', (string) json_encode($backup['data']));

        self::getContainer()->get(RestoreService::class)
            ->restoreFromBackup($backup, ['best_effort' => true], $own);
        $this->em->clear();

        $ownAfter = $this->em->find(Tenant::class, $ownId);
        self::assertNull($ownAfter?->getParent(), 'the organisation is not moved under a holding');
        self::assertFalse($ownAfter?->isCorporateParent() ?? true, 'the holding flag is not taken from the file');
        self::assertTrue($ownAfter?->isActive() ?? false, 'the active state is not taken from the file');

        $colleagueAfter = $this->em->find(User::class, $colleagueId);
        self::assertSame('live-hash', $colleagueAfter?->getPassword(), 'no password hash from the file');
        self::assertNull($colleagueAfter?->getSsoExternalId(), 'no SSO subject from the file');
        self::assertNotContains('ROLE_ALLOWED_TO_SWITCH', $colleagueAfter?->getRoles() ?? []);

        $switcher = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($switcher !== null) {
            self::assertNotSame('forged-hash', $switcher->getPassword());
            foreach ($switcher->getRoles() as $role) {
                self::assertFalse(\App\Security\RoleNames::isPrivileged($role), 'no privileged role: ' . $role);
            }
            self::assertCount(0, $switcher->getCustomRoles(), 'no privileged custom role');
        }

        self::assertNull(
            $this->em->getRepository(AuditLog::class)->findOneBy(['description' => 'forged evidence']),
            'an organisation restore adds no audit-log rows',
        );
    }

    private function tenant(string $label): Tenant
    {
        $suffix = substr(uniqid(), -8);
        $tenant = (new Tenant())->setName('Guard ' . $label . ' ' . $suffix)->setCode('grd_' . $label . '_' . $suffix);
        $tenant->setIsActive(true);
        $this->em->persist($tenant);

        return $tenant;
    }

    private function asset(string $name, Tenant $tenant): Asset
    {
        $asset = new Asset();
        $asset->setName($name);
        $asset->setAssetType('software');
        $asset->setConfidentialityValue(3);
        $asset->setIntegrityValue(3);
        $asset->setAvailabilityValue(3);
        $asset->setTenant($tenant);
        $this->em->persist($asset);

        return $asset;
    }

    private function supplier(string $name, Tenant $tenant): Supplier
    {
        $supplier = (new Supplier())->setName($name)->setServiceProvided('Hosting')->setTenant($tenant);
        $this->em->persist($supplier);

        return $supplier;
    }
}
