<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ScheduledReport;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Mail\RecipientFilter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * ISB MINOR-4: tenant + role gate at recipient-filter level.
 *
 * These tests exercise RecipientFilter directly, which is the collaborator
 * ScheduledReportService delegates to before ever talking to the mailer.
 */
class ScheduledReportServiceRecipientFilterTest extends TestCase
{
    private UserRepository&MockObject $userRepository;
    private RecipientFilter $filter;
    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->filter = new RecipientFilter($this->userRepository);

        $this->tenantA = $this->makeTenant(1);
        $this->tenantB = $this->makeTenant(2);
    }

    public function testManagerInSameTenantIsAccepted(): void
    {
        $user = $this->makeUser('manager@tenant-a.test', $this->tenantA, ['ROLE_MANAGER']);
        $this->userRepository->method('findOneBy')->willReturn($user);

        $result = $this->filter->filter($this->makeReport(1, ['manager@tenant-a.test']));

        self::assertSame(['manager@tenant-a.test'], $result['valid']);
        self::assertSame([], $result['dropped']);
    }

    public function testUserInSameTenantIsDroppedAsRoleTooLow(): void
    {
        $user = $this->makeUser('user@tenant-a.test', $this->tenantA, ['ROLE_USER']);
        $this->userRepository->method('findOneBy')->willReturn($user);

        $result = $this->filter->filter($this->makeReport(1, ['user@tenant-a.test']));

        self::assertSame([], $result['valid']);
        self::assertSame(
            [['email' => 'user@tenant-a.test', 'reason' => RecipientFilter::REASON_ROLE_TOO_LOW]],
            $result['dropped'],
        );
    }

    public function testManagerInOtherTenantIsDroppedAsCrossTenant(): void
    {
        $foreign = $this->makeUser('manager@tenant-b.test', $this->tenantB, ['ROLE_MANAGER']);
        $this->userRepository->method('findOneBy')->willReturn($foreign);

        $result = $this->filter->filter($this->makeReport(1, ['manager@tenant-b.test']));

        self::assertSame([], $result['valid']);
        self::assertSame(
            [['email' => 'manager@tenant-b.test', 'reason' => RecipientFilter::REASON_CROSS_TENANT]],
            $result['dropped'],
        );
    }

    public function testEmailNotInDatabaseIsDroppedAsUnknownUser(): void
    {
        $this->userRepository->method('findOneBy')->willReturn(null);

        $result = $this->filter->filter($this->makeReport(1, ['ghost@tenant-a.test']));

        self::assertSame([], $result['valid']);
        self::assertSame(
            [['email' => 'ghost@tenant-a.test', 'reason' => RecipientFilter::REASON_UNKNOWN_USER]],
            $result['dropped'],
        );
    }

    public function testAdminAndSuperAdminAreAccepted(): void
    {
        $admin = $this->makeUser('admin@tenant-a.test', $this->tenantA, ['ROLE_ADMIN']);
        $super = $this->makeUser('super@tenant-a.test', $this->tenantA, ['ROLE_SUPER_ADMIN']);

        $this->userRepository->method('findOneBy')->willReturnCallback(static function (array $c) use ($admin, $super) {
            return match ($c['email'] ?? null) {
                'admin@tenant-a.test' => $admin,
                'super@tenant-a.test' => $super,
                default => null,
            };
        });

        $result = $this->filter->filter($this->makeReport(1, [
            'admin@tenant-a.test',
            'super@tenant-a.test',
        ]));

        self::assertSame(['admin@tenant-a.test', 'super@tenant-a.test'], $result['valid']);
        self::assertSame([], $result['dropped']);
    }

    public function testMixedRosterReturnsBothBuckets(): void
    {
        $manager = $this->makeUser('m@tenant-a.test', $this->tenantA, ['ROLE_MANAGER']);
        $user = $this->makeUser('u@tenant-a.test', $this->tenantA, ['ROLE_USER']);
        $foreign = $this->makeUser('x@tenant-b.test', $this->tenantB, ['ROLE_MANAGER']);

        $this->userRepository->method('findOneBy')->willReturnCallback(static function (array $c) use ($manager, $user, $foreign) {
            return match ($c['email'] ?? null) {
                'm@tenant-a.test' => $manager,
                'u@tenant-a.test' => $user,
                'x@tenant-b.test' => $foreign,
                default => null,
            };
        });

        $result = $this->filter->filter($this->makeReport(1, [
            'm@tenant-a.test',
            'u@tenant-a.test',
            'x@tenant-b.test',
            'ghost@tenant-a.test',
        ]));

        self::assertSame(['m@tenant-a.test'], $result['valid']);
        self::assertCount(3, $result['dropped']);
    }

    public function testValidateSingleRolesAndTenancy(): void
    {
        $manager = $this->makeUser('m@tenant-a.test', $this->tenantA, ['ROLE_MANAGER']);
        $user = $this->makeUser('u@tenant-a.test', $this->tenantA, ['ROLE_USER']);
        $foreign = $this->makeUser('x@tenant-b.test', $this->tenantB, ['ROLE_MANAGER']);

        $this->userRepository->method('findOneBy')->willReturnCallback(static function (array $c) use ($manager, $user, $foreign) {
            return match ($c['email'] ?? null) {
                'm@tenant-a.test' => $manager,
                'u@tenant-a.test' => $user,
                'x@tenant-b.test' => $foreign,
                default => null,
            };
        });

        self::assertNull($this->filter->validateSingle('m@tenant-a.test', 1));
        self::assertSame(RecipientFilter::REASON_ROLE_TOO_LOW, $this->filter->validateSingle('u@tenant-a.test', 1));
        self::assertSame(RecipientFilter::REASON_CROSS_TENANT, $this->filter->validateSingle('x@tenant-b.test', 1));
        self::assertSame(RecipientFilter::REASON_UNKNOWN_USER, $this->filter->validateSingle('ghost@tenant-a.test', 1));
    }

    private function makeReport(int $tenantId, array $recipients): ScheduledReport
    {
        $report = new ScheduledReport();
        $report->setTenantId($tenantId);
        $report->setRecipients($recipients);
        return $report;
    }

    private function makeTenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $ref = new \ReflectionProperty(Tenant::class, 'id');
        $ref->setValue($tenant, $id);
        return $tenant;
    }

    private function makeUser(string $email, Tenant $tenant, array $roles): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setTenant($tenant);
        $user->setIsActive(true);
        return $user;
    }
}
