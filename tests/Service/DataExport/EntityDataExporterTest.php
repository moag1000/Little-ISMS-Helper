<?php

declare(strict_types=1);

namespace App\Tests\Service\DataExport;

use App\Entity\ComplianceFramework;
use App\Entity\CustomReport;
use App\Entity\MfaToken;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserSession;
use App\Service\DataExport\EntityDataExporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The generic entity export is shared by the sync admin endpoint and the
 * async ExportDataJob. It runs here without an HTTP request, i.e. with the
 * Doctrine tenant filter unarmed — so any scoping seen is the exporter's own.
 */
final class EntityDataExporterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EntityDataExporter $exporter;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->exporter = self::getContainer()->get(EntityDataExporter::class);
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
    public function tenantScopedExportCoversAssociationAndIntTenantIdEntities(): void
    {
        $tenantA = $this->tenant('a');
        $tenantB = $this->tenant('b');
        $userA = $this->user($tenantA, 'exp-own');
        $userB = $this->user($tenantB, 'exp-foreign');
        $this->em->flush();
        $this->report($tenantA, $userA, 'OwnReport');
        $this->report($tenantB, $userB, 'ForeignReport');
        $this->em->flush();

        $data = $this->exporter->export(
            [User::class, CustomReport::class, Tenant::class],
            $tenantA,
        );

        $emails = array_column($data['User'], 'email');
        self::assertContains($userA->getEmail(), $emails);
        self::assertNotContains($userB->getEmail(), $emails);
        self::assertArrayNotHasKey('password', $data['User'][0]);

        // CustomReport carries an int tenantId the Doctrine filter ignores.
        $names = array_column($data['CustomReport'], 'name');
        self::assertContains('OwnReport', $names);
        self::assertNotContains('ForeignReport', $names);

        self::assertSame([$tenantA->getId()], array_column($data['Tenant'], 'id'));
    }

    #[Test]
    public function credentialTablesAreNeverExportableAndGlobalTablesOnlyInstanceWide(): void
    {
        $tenant = $this->tenant('c');
        $this->em->flush();

        foreach ([MfaToken::class, UserSession::class] as $denied) {
            self::assertFalse($this->exporter->isExportable($denied, $tenant), $denied);
            self::assertFalse($this->exporter->isExportable($denied, null), $denied . ' (instance-wide)');
        }
        self::assertSame([], $this->exporter->export([MfaToken::class], null));

        self::assertFalse($this->exporter->isExportable(ComplianceFramework::class, $tenant));
        self::assertTrue($this->exporter->isExportable(ComplianceFramework::class, null));
        self::assertFalse($this->exporter->isExportable('Symfony\\Component\\HttpFoundation\\Request', null));

        $offered = array_column($this->exporter->listExportableEntities($tenant), 'class');
        self::assertContains(User::class, $offered);
        self::assertNotContains(MfaToken::class, $offered);
        self::assertNotContains(ComplianceFramework::class, $offered);
    }

    #[Test]
    #[DataProvider('sensitiveFields')]
    public function secretFieldsAreRecognised(string $field, bool $sensitive): void
    {
        self::assertSame($sensitive, EntityDataExporter::isSensitiveField($field));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function sensitiveFields(): iterable
    {
        yield 'password' => ['password', true];
        yield 'mfa secret' => ['secret', true];
        yield 'backup codes' => ['backupCodes', true];
        yield 'encrypted client secret' => ['clientSecretEncrypted', true];
        yield 'api token hash' => ['tokenHash', true];
        yield 'session id' => ['sessionId', true];
        yield 'azure metadata' => ['azureMetadata', true];
        yield 'plain name' => ['name', false];
        yield 'email' => ['email', false];
    }

    private function tenant(string $suffix): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Export ' . $suffix);
        $tenant->setCode('edet_' . $suffix . '_' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function user(Tenant $tenant, string $marker): User
    {
        $user = new User();
        $user->setEmail($marker . '_' . uniqid() . '@example.com');
        $user->setFirstName($marker);
        $user->setLastName('Export');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('secret_hash');
        $user->setTenant($tenant);
        $this->em->persist($user);

        return $user;
    }

    private function report(Tenant $tenant, User $owner, string $name): void
    {
        $report = new CustomReport();
        $report->setName($name);
        $report->setOwner($owner);
        $report->setTenantId((int) $tenant->getId());
        $this->em->persist($report);
    }
}
