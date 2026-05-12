<?php

declare(strict_types=1);

namespace App\Tests\Service\Import\Mapper;

use App\Entity\BusinessProcess;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\BusinessProcessRepository;
use App\Repository\UserRepository;
use App\Service\Import\Mapper\BusinessProcessMapper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class BusinessProcessMapperTest extends TestCase
{
    private MockObject $em;
    private MockObject $businessProcessRepository;
    private MockObject $userRepository;
    private BusinessProcessMapper $mapper;

    protected function setUp(): void
    {
        $this->em                        = $this->createMock(EntityManagerInterface::class);
        $this->businessProcessRepository = $this->createMock(BusinessProcessRepository::class);
        $this->userRepository            = $this->createMock(UserRepository::class);

        $this->mapper = new BusinessProcessMapper(
            $this->em,
            $this->businessProcessRepository,
            $this->userRepository,
        );
    }

    // ─── supportsEntityType ───────────────────────────────────────────────────

    #[Test]
    public function supportsEntityTypeReturnsTrueForBusinessProcess(): void
    {
        self::assertTrue($this->mapper->supportsEntityType('BusinessProcess'));
    }

    #[Test]
    public function supportsEntityTypeReturnsFalseForOthers(): void
    {
        self::assertFalse($this->mapper->supportsEntityType('Asset'));
        self::assertFalse($this->mapper->supportsEntityType('Risk'));
        self::assertFalse($this->mapper->supportsEntityType('business_process'));
    }

    // ─── validate — required fields ───────────────────────────────────────────

    #[Test]
    public function validateRequiresName(): void
    {
        $result = $this->mapper->validate(['criticality' => 'high']);
        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('name', $result['errors'][0]);
    }

    #[Test]
    public function validateRequiresCriticality(): void
    {
        $result = $this->mapper->validate(['name' => 'Auftragsabwicklung']);
        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('criticality', $result['errors'][0]);
    }

    #[Test]
    public function validateRejectsUnknownCriticality(): void
    {
        $result = $this->mapper->validate([
            'name'        => 'Process A',
            'criticality' => 'ultra',
        ]);
        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('criticality', $result['errors'][0]);
    }

    // ─── validate — numeric fields ────────────────────────────────────────────

    #[Test]
    public function validateRejectsNegativeRto(): void
    {
        $result = $this->mapper->validate([
            'name'        => 'Process A',
            'criticality' => 'high',
            'rto'         => '-1',
        ]);
        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('rto', $result['errors'][0]);
    }

    #[Test]
    public function validateAcceptsZeroRto(): void
    {
        $result = $this->mapper->validate([
            'name'        => 'Process A',
            'criticality' => 'high',
            'rto'         => '0',
        ]);
        self::assertEmpty($result['errors']);
    }

    // ─── validate — happy path ────────────────────────────────────────────────

    #[Test]
    public function validatePassesMinimalValidRow(): void
    {
        $result = $this->mapper->validate([
            'name'        => 'Kundendienst',
            'criticality' => 'critical',
        ]);
        self::assertEmpty($result['errors']);
        self::assertEmpty($result['warnings']);
    }

    #[Test]
    public function validatePassesFullValidRow(): void
    {
        $result = $this->mapper->validate([
            'name'                   => 'Kundendienst',
            'criticality'            => 'high',
            'rto'                    => '4',
            'rpo'                    => '1',
            'mtpd'                   => '8',
            'processOwner'           => 'ops@example.com',
            'financialImpactPerHour' => '5000.00',
            'description'            => 'Customer service operations',
        ]);
        self::assertEmpty($result['errors']);
    }

    #[Test]
    public function validateEmitsWarningForNonEmailOwner(): void
    {
        $result = $this->mapper->validate([
            'name'         => 'Process A',
            'criticality'  => 'medium',
            'processOwner' => 'John Doe',
        ]);
        self::assertEmpty($result['errors']);
        self::assertNotEmpty($result['warnings']);
    }

    #[Test]
    public function validateEmitsWarningForNegativeFinancialImpact(): void
    {
        $result = $this->mapper->validate([
            'name'                   => 'Process A',
            'criticality'            => 'low',
            'financialImpactPerHour' => '-500',
        ]);
        self::assertEmpty($result['errors']);
        self::assertNotEmpty($result['warnings']);
    }

    // ─── toEntityData ─────────────────────────────────────────────────────────

    #[Test]
    public function toEntityDataMapsRequiredFields(): void
    {
        $data = $this->mapper->toEntityData([
            'name'        => 'Kundendienst',
            'criticality' => 'critical',
        ]);

        self::assertSame('Kundendienst', $data['name']);
        self::assertSame('critical', $data['criticality']);
    }

    #[Test]
    public function toEntityDataMapsRtoRpoMtpd(): void
    {
        $data = $this->mapper->toEntityData([
            'name'        => 'Kundendienst',
            'criticality' => 'high',
            'rto'         => '4',
            'rpo'         => '1',
            'mtpd'        => '8',
        ]);

        self::assertSame(4, $data['rto']);
        self::assertSame(1, $data['rpo']);
        self::assertSame(8, $data['mtpd']);
    }

    #[Test]
    public function toEntityDataMapsMtpdViaMaxAusfallzeit(): void
    {
        $data = $this->mapper->toEntityData([
            'name'           => 'Prozess A',
            'criticality'    => 'medium',
            'max_ausfallzeit' => '24',
        ]);

        self::assertSame(24, $data['mtpd']);
    }

    #[Test]
    public function toEntityDataMapsFinancialImpactPerHour(): void
    {
        $data = $this->mapper->toEntityData([
            'name'                   => 'Process A',
            'criticality'            => 'high',
            'financialImpactPerHour' => '5000.50',
        ]);

        self::assertSame('5000.5', $data['financialImpactPerHour']);
    }

    #[Test]
    public function toEntityDataDefaultsCriticalityToLowWhenInvalid(): void
    {
        $data = $this->mapper->toEntityData([
            'name'        => 'Process A',
            'criticality' => 'ultra',
        ]);

        self::assertSame('low', $data['criticality']);
    }

    #[Test]
    public function toEntityDataStoresOwnerAsStringWhenNotEmail(): void
    {
        $data = $this->mapper->toEntityData([
            'name'         => 'Process A',
            'criticality'  => 'medium',
            'processOwner' => 'Jane Doe',
        ]);

        self::assertSame('Jane Doe', $data['processOwner']);
    }

    #[Test]
    public function toEntityDataDefaultsOwnerToImported(): void
    {
        $data = $this->mapper->toEntityData([
            'name'        => 'Process A',
            'criticality' => 'low',
        ]);

        self::assertSame('Imported', $data['processOwner']);
    }

    // ─── resolveOwnerUser ─────────────────────────────────────────────────────

    #[Test]
    public function resolveOwnerUserReturnsNullWhenOwnerMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $result = $this->mapper->resolveOwnerUser(['name' => 'Process A', 'criticality' => 'high'], $tenant);
        self::assertNull($result);
    }

    #[Test]
    public function resolveOwnerUserReturnsNullWhenOwnerIsNotEmail(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $result = $this->mapper->resolveOwnerUser([
            'name'         => 'Process A',
            'criticality'  => 'high',
            'processOwner' => 'John Doe',
        ], $tenant);
        self::assertNull($result);
    }

    #[Test]
    public function resolveOwnerUserResolvesEmailToUser(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $user   = $this->createMock(User::class);

        $this->userRepository
            ->method('findOneBy')
            ->willReturn($user);

        $result = $this->mapper->resolveOwnerUser([
            'name'         => 'Process A',
            'criticality'  => 'high',
            'processOwner' => 'ops@example.com',
        ], $tenant);

        self::assertSame($user, $result);
    }

    // ─── findExisting ─────────────────────────────────────────────────────────

    #[Test]
    public function findExistingReturnsNullWhenNameMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $result = $this->mapper->findExisting(['criticality' => 'high'], $tenant);
        self::assertNull($result);
    }

    #[Test]
    public function findExistingQueriesDatabase(): void
    {
        $tenant  = $this->createMock(Tenant::class);
        $process = $this->createMock(BusinessProcess::class);

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($process);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->em->method('createQueryBuilder')->willReturn($qb);

        $result = $this->mapper->findExisting([
            'name'        => 'Kundendienst',
            'criticality' => 'critical',
        ], $tenant);

        self::assertSame($process, $result);
    }
}
