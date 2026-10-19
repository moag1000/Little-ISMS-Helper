<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Entity\User;
use App\Job\ExportUsersJob;
use App\Job\JobContext;
use App\Repository\UserRepository;
use App\Service\Job\JobStatusService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Security hotfix (admin scope): the async user export runs detached from the
 * request, so the actor's scope is passed as args. It must be fail-closed —
 * only an explicit `global: true` (SUPER_ADMIN) dumps every user.
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
    public function tenantScopedArgsOnlyExportThoseTenants(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects(self::never())->method('findAll');
        $repo->expects(self::once())->method('findBy')
            ->with(['tenant' => [3, 7]])
            ->willReturn([$this->user('scoped@example.com')]);

        $csv = $this->exportCsv($repo, ['tenantIds' => [3, '7']]);

        self::assertStringContainsString('scoped@example.com', $csv);
    }

    #[Test]
    public function missingScopeArgsExportNobody(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects(self::never())->method('findAll');
        $repo->expects(self::never())->method('findBy');

        $csv = $this->exportCsv($repo, []);

        self::assertSame(1, substr_count(trim($csv), "\n") + 1, 'header row only');
    }

    #[Test]
    public function globalArgExportsAllUsers(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects(self::once())->method('findAll')->willReturn([$this->user('everyone@example.com')]);

        $csv = $this->exportCsv($repo, ['global' => true]);

        self::assertStringContainsString('everyone@example.com', $csv);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function exportCsv(UserRepository $repo, array $args): string
    {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->projectDir);

        $statusService = static::getContainer()->get(JobStatusService::class);
        $jobId = $statusService->create('test.export_users_scope', []);

        (new ExportUsersJob($repo, $kernel))->run(new JobContext($jobId, $statusService, $args));

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
