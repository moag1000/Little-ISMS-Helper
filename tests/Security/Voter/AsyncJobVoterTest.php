<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\AsyncJobVoter;
use App\Service\Job\JobStatusService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Job status / download links are no longer protected by the UUID alone:
 * JobStatusService records the dispatching user + tenant and AsyncJobVoter
 * only lets that owner (or a SUPER_ADMIN) in.
 */
final class AsyncJobVoterTest extends TestCase
{
    private string $tmpDir;
    private KernelInterface $kernel;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/async-job-voter-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/var/jobs', 0o775, true);
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->tmpDir);
        $this->kernel = $kernel;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/var/jobs/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir . '/var/jobs');
        @rmdir($this->tmpDir . '/var');
        @rmdir($this->tmpDir);
    }

    #[Test]
    public function createRecordsOwnerAndTenant(): void
    {
        $owner = $this->user(7, 70);
        $jobId = $this->service($owner)->create('test.job');

        $record = $this->service(null)->read($jobId);
        self::assertSame(7, $record['owner_user_id']);
        self::assertSame(70, $record['owner_tenant_id']);
    }

    #[Test]
    public function ownerIsGrantedOthersAreDenied(): void
    {
        $owner = $this->user(7, 70);
        $jobId = $this->service($owner)->create('test.job');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($owner, $jobId));
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($this->user(8, 70), $jobId),
            'a colleague of the same organisation must not read the owner\'s export',
        );
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($this->user(7, 71), $jobId),
            'the owner moved to another organisation — the job stays with the old one',
        );
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->user(9, 99), $jobId, superAdmin: true));
    }

    #[Test]
    public function ownerlessUnknownAndMalformedJobsAreDenied(): void
    {
        $ownerlessJob = $this->service(null)->create('quick_fix.job');
        $user = $this->user(7, 70);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($user, $ownerlessJob));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($user, $ownerlessJob, superAdmin: true));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($user, '11111111-1111-4111-8111-111111111111'));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($user, '../../etc/passwd'));
    }

    private function vote(User $user, string $jobId, bool $superAdmin = false): int
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn(string $attribute): bool => $attribute === 'ROLE_SUPER_ADMIN' && $superAdmin,
        );
        $voter = new AsyncJobVoter($this->service(null), $security);

        return $voter->vote(new UsernamePasswordToken($user, 'main', $user->getRoles()), $jobId, [AsyncJobVoter::ACCESS]);
    }

    private function service(?User $currentUser): JobStatusService
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($currentUser);

        return new JobStatusService($this->kernel, $security);
    }

    private function user(int $id, int $tenantId): User
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($tenantId);
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getTenant')->willReturn($tenant);
        $user->method('getRoles')->willReturn(['ROLE_USER']);
        $user->method('getUserIdentifier')->willReturn('user' . $id);

        return $user;
    }
}
