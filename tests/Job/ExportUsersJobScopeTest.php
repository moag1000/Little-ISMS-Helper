<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Entity\User;
use App\Exception\Tenant\TenantNotFoundException;
use App\Job\ExportUsersJob;
use App\Job\JobContext;
use App\Repository\UserRepository;
use App\Service\Job\JobStatusService;
use App\Service\Job\JobTenantScope;
use App\Service\User\UserListScope;
use Symfony\Bundle\SecurityBundle\Security;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Security hotfix (admin scope): the async user export runs detached from the
 * request, so the actor's user-list scope is passed as args (UserListScope:
 * the organisation ids of the users the actor sees in the index). It must be
 * fail-closed — only an explicit `allTenants: true` (SUPER_ADMIN) dumps every
 * user; missing scope never exports anybody.
 */
final class ExportUsersJobScopeTest extends KernelTestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->projectDir = sys_get_temp_dir() . '/export_users_scope_' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/var/exports', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->projectDir . '/var/exports/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->projectDir . '/var/exports');
        @rmdir($this->projectDir . '/var');
        @rmdir($this->projectDir);
        parent::tearDown();
    }

    #[Test]
    public function tenantIdListOnlyExportsThoseOrganisations(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects(self::never())->method('findAllWithRoles');
        $repo->expects(self::once())->method('findByTenantIdsWithRoles')
            ->with([3, 7])
            ->willReturn([$this->user('scoped@example.com')]);

        $csv = $this->exportCsv($repo, [UserListScope::ARG_TENANT_IDS => [3, '7']]);

        self::assertStringContainsString('scoped@example.com', $csv);
    }

    #[Test]
    public function legacySingleTenantArgIsStillHonoured(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects(self::once())->method('findByTenantIdsWithRoles')->with([3])->willReturn([]);

        $this->exportCsv($repo, [JobTenantScope::ARG_TENANT_ID => 3]);
    }

    #[Test]
    public function missingScopeArgsExportNobody(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects(self::never())->method('findAllWithRoles');
        $repo->expects(self::never())->method('findByTenantIdsWithRoles');

        $this->expectException(TenantNotFoundException::class);
        $this->exportCsv($repo, []);
    }

    #[Test]
    public function emptyTenantIdListExportsNobody(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects(self::never())->method('findAllWithRoles');
        $repo->expects(self::never())->method('findByTenantIdsWithRoles');

        $this->expectException(TenantNotFoundException::class);
        $this->exportCsv($repo, [UserListScope::ARG_TENANT_IDS => []]);
    }

    #[Test]
    public function allTenantsArgExportsAllUsers(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects(self::once())->method('findAllWithRoles')->willReturn([$this->user('everyone@example.com')]);

        $csv = $this->exportCsv($repo, [JobTenantScope::ARG_ALL_TENANTS => true]);

        self::assertStringContainsString('everyone@example.com', $csv);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function exportCsv(UserRepository $repo, array $args): string
    {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->projectDir);
        $scope = new UserListScope($this->createStub(Security::class), $repo);

        $statusService = static::getContainer()->get(JobStatusService::class);
        $jobId = $statusService->create('test.export_users_scope', []);

        (new ExportUsersJob($scope, $kernel))->run(new JobContext($jobId, $statusService, $args));

        return (string) file_get_contents($this->projectDir . '/var/exports/' . $jobId . '.csv');
    }

    private function user(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setFirstName('F');
        $user->setLastName('L');

        return $user;
    }
}
