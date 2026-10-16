<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Entity\Asset;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Exception\Tenant\TenantNotFoundException;
use App\Job\AsyncJobInterface;
use App\Job\ExportAnalyticsJob;
use App\Job\ExportDataJob;
use App\Job\ExportRisksJob;
use App\Job\ExportUsersJob;
use App\Job\JobContext;
use App\Service\Job\JobStatusService;
use App\Service\Job\JobTenantScope;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Async export jobs must scope every query to the tenant passed in their
 * args — never to "whatever the Doctrine tenant filter says". Outside an
 * HTTP request (Messenger worker, after fastcgi_finish_request) the filter is
 * unarmed, which is exactly the state of this KernelTestCase: if a job fell
 * back to findAll() the other tenant's rows would show up in the file.
 *
 * Two tenants with data each; with a single tenant the leak is invisible.
 */
final class ExportJobsTenantScopeTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private JobStatusService $jobStatusService;
    /** @var list<string> */
    private array $jobIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->jobStatusService = self::getContainer()->get(JobStatusService::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        $exportDir = self::getContainer()->getParameter('kernel.project_dir') . '/var/exports/';
        foreach ($this->jobIds as $jobId) {
            $this->jobStatusService->delete($jobId);
            @unlink($exportDir . $jobId . '.csv');
        }
        parent::tearDown();
    }

    #[Test]
    public function exportUsersJobOnlyWritesUsersOfTheDispatchingTenant(): void
    {
        [$tenantA, $tenantB] = $this->twoTenants();
        $this->user($tenantA, 'own-user');
        $this->user($tenantB, 'foreign-user');
        $this->em->flush();

        $csv = $this->runJob(ExportUsersJob::class, [JobTenantScope::ARG_TENANT_ID => $tenantA->getId()]);

        self::assertStringContainsString('own-user', $csv);
        self::assertStringNotContainsString('foreign-user', $csv);
    }

    #[Test]
    public function exportAnalyticsJobOnlyWritesRowsOfTheDispatchingTenant(): void
    {
        [$tenantA, $tenantB] = $this->twoTenants();
        $this->asset($tenantA, 'OwnAsset');
        $this->asset($tenantB, 'ForeignAsset');
        $this->em->flush();

        $csv = $this->runJob(ExportAnalyticsJob::class, [
            'type' => 'assets',
            JobTenantScope::ARG_TENANT_ID => $tenantA->getId(),
        ]);

        self::assertStringContainsString('OwnAsset', $csv);
        self::assertStringNotContainsString('ForeignAsset', $csv);
    }

    #[Test]
    public function exportRisksJobOnlyWritesRisksOfTheDispatchingTenant(): void
    {
        [$tenantA, $tenantB] = $this->twoTenants();
        $this->risk($tenantA, 'OwnRisk');
        $this->risk($tenantB, 'ForeignRisk');
        $this->em->flush();

        $csv = $this->runJob(ExportRisksJob::class, [JobTenantScope::ARG_TENANT_ID => $tenantA->getId()]);

        self::assertStringContainsString('OwnRisk', $csv);
        self::assertStringNotContainsString('ForeignRisk', $csv);
    }

    #[Test]
    public function exportDataJobScopesRowsAndStripsSecrets(): void
    {
        [$tenantA, $tenantB] = $this->twoTenants();
        $this->user($tenantA, 'own-user');
        $this->user($tenantB, 'foreign-user');
        $this->em->flush();

        $csv = $this->runJob(ExportDataJob::class, [
            'entities' => [User::class, \App\Entity\MfaToken::class],
            'format' => 'csv',
            JobTenantScope::ARG_TENANT_ID => $tenantA->getId(),
        ]);

        self::assertStringContainsString('own-user', $csv);
        self::assertStringNotContainsString('foreign-user', $csv);
        self::assertStringNotContainsString('hashed_password', $csv);
        self::assertStringNotContainsString('# MfaToken', $csv);
    }

    /**
     * @param class-string<AsyncJobInterface> $jobClass
     * @param array<string, mixed>           $args
     */
    #[Test]
    #[DataProvider('jobsWithoutUsableScope')]
    public function jobWithoutUsableTenantScopeFailsInsteadOfExportingEverything(string $jobClass, array $args): void
    {
        $this->expectException(TenantNotFoundException::class);
        $this->runJob($jobClass, $args);
    }

    /**
     * @return iterable<string, array{class-string<AsyncJobInterface>, array<string, mixed>}>
     */
    public static function jobsWithoutUsableScope(): iterable
    {
        yield 'users, no scope' => [ExportUsersJob::class, []];
        yield 'analytics, no scope' => [ExportAnalyticsJob::class, ['type' => 'risks']];
        yield 'risks, no scope' => [ExportRisksJob::class, []];
        yield 'risks, unknown tenant' => [ExportRisksJob::class, [JobTenantScope::ARG_TENANT_ID => 999999999]];
        yield 'data, no scope' => [ExportDataJob::class, ['entities' => [User::class], 'format' => 'csv']];
        yield 'users, allTenants not strictly true' => [ExportUsersJob::class, [JobTenantScope::ARG_ALL_TENANTS => '1']];
    }

    /**
     * @param class-string<AsyncJobInterface> $jobClass
     * @param array<string, mixed>           $args
     */
    private function runJob(string $jobClass, array $args): string
    {
        $jobId = $this->jobStatusService->create('test.export_scope', []);
        $this->jobIds[] = $jobId;

        /** @var AsyncJobInterface $job */
        $job = self::getContainer()->get($jobClass);
        $job->run(new JobContext($jobId, $this->jobStatusService, $args));

        $path = self::getContainer()->getParameter('kernel.project_dir') . '/var/exports/' . $jobId . '.csv';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * @return array{Tenant, Tenant}
     */
    private function twoTenants(): array
    {
        $tenants = [];
        foreach (['a', 'b'] as $suffix) {
            $tenant = new Tenant();
            $tenant->setName('Export Scope ' . $suffix);
            $tenant->setCode('ejts_' . $suffix . '_' . uniqid());
            $this->em->persist($tenant);
            $tenants[] = $tenant;
        }

        return [$tenants[0], $tenants[1]];
    }

    private function user(Tenant $tenant, string $marker): void
    {
        $user = new User();
        $user->setEmail($marker . '_' . uniqid() . '@example.com');
        $user->setFirstName($marker);
        $user->setLastName('Scope');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('hashed_password');
        $user->setTenant($tenant);
        $this->em->persist($user);
    }

    private function asset(Tenant $tenant, string $name): void
    {
        $asset = new Asset();
        $asset->setName($name . '_' . uniqid());
        $asset->setAssetType('software');
        $asset->setConfidentialityValue(3);
        $asset->setIntegrityValue(3);
        $asset->setAvailabilityValue(3);
        $asset->setTenant($tenant);
        $this->em->persist($asset);
    }

    private function risk(Tenant $tenant, string $title): void
    {
        $risk = new Risk();
        $risk->setTitle($title . '_' . uniqid());
        $risk->setCategory('availability');
        $risk->setDescription('Scope probe');
        $risk->setProbability(3);
        $risk->setImpact(3);
        $risk->setTenant($tenant);
        $this->em->persist($risk);
    }
}
