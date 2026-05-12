<?php

declare(strict_types=1);

namespace App\Tests\Service\Import\Mapper;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\RiskRepository;
use App\Repository\UserRepository;
use App\Service\Import\Mapper\RiskMapper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class RiskMapperTest extends TestCase
{
    private MockObject $em;
    private MockObject $riskRepository;
    private MockObject $userRepository;
    private RiskMapper $mapper;

    protected function setUp(): void
    {
        $this->em             = $this->createMock(EntityManagerInterface::class);
        $this->riskRepository = $this->createMock(RiskRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);

        $this->mapper = new RiskMapper(
            $this->em,
            $this->riskRepository,
            $this->userRepository,
        );
    }

    // ─── supportsEntityType ───────────────────────────────────────────────────

    #[Test]
    public function supportsEntityTypeReturnsTrueForRisk(): void
    {
        self::assertTrue($this->mapper->supportsEntityType('Risk'));
    }

    #[Test]
    public function supportsEntityTypeReturnsFalseForOthers(): void
    {
        self::assertFalse($this->mapper->supportsEntityType('Asset'));
        self::assertFalse($this->mapper->supportsEntityType('BusinessProcess'));
        self::assertFalse($this->mapper->supportsEntityType('Control'));
    }

    // ─── validate — required fields ───────────────────────────────────────────

    #[Test]
    public function validateRequiresName(): void
    {
        $result = $this->mapper->validate(['category' => 'security']);
        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('name', $result['errors'][0]);
    }

    #[Test]
    public function validateRequiresCategory(): void
    {
        $result = $this->mapper->validate(['name' => 'Ransomware']);
        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('category', $result['errors'][0]);
    }

    #[Test]
    public function validateRejectsUnknownCategory(): void
    {
        $result = $this->mapper->validate([
            'name'     => 'Ransomware',
            'category' => 'unknown_cat',
        ]);
        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('category', $result['errors'][0]);
    }

    // ─── validate — impact range ──────────────────────────────────────────────

    #[Test]
    public function validateRejectsImpactBelowOne(): void
    {
        $result = $this->mapper->validate([
            'name'          => 'Risk A',
            'category'      => 'security',
            'inherentImpact' => '0',
        ]);
        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('inherentImpact', $result['errors'][0]);
    }

    #[Test]
    public function validateRejectsImpactAboveFive(): void
    {
        $result = $this->mapper->validate([
            'name'          => 'Risk A',
            'category'      => 'security',
            'inherentImpact' => '6',
        ]);
        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('inherentImpact', $result['errors'][0]);
    }

    #[Test]
    public function validateAcceptsValidImpactRange(): void
    {
        $result = $this->mapper->validate([
            'name'              => 'Risk A',
            'category'          => 'security',
            'inherentImpact'    => '3',
            'inherentLikelihood' => '2',
        ]);
        self::assertEmpty($result['errors']);
    }

    // ─── validate — happy path ────────────────────────────────────────────────

    #[Test]
    public function validatePassesMinimalValidRow(): void
    {
        $result = $this->mapper->validate([
            'name'     => 'Data Breach',
            'category' => 'security',
        ]);
        self::assertEmpty($result['errors']);
        self::assertEmpty($result['warnings']);
    }

    #[Test]
    public function validateEmitsWarningForNonEmailOwner(): void
    {
        $result = $this->mapper->validate([
            'name'      => 'Risk A',
            'category'  => 'security',
            'riskOwner' => 'John Doe',
        ]);
        self::assertEmpty($result['errors']);
        self::assertNotEmpty($result['warnings']);
    }

    #[Test]
    public function validateEmitsWarningForUnknownTreatmentStrategy(): void
    {
        $result = $this->mapper->validate([
            'name'              => 'Risk A',
            'category'          => 'security',
            'treatmentStrategy' => 'ignore',
        ]);
        self::assertEmpty($result['errors']);
        self::assertNotEmpty($result['warnings']);
    }

    // ─── toEntityData ─────────────────────────────────────────────────────────

    #[Test]
    public function toEntityDataMapsRequiredFields(): void
    {
        $data = $this->mapper->toEntityData([
            'name'     => 'Datenpanne',
            'category' => 'security',
        ]);

        self::assertSame('Datenpanne', $data['title']);
        self::assertSame('security', $data['category']);
    }

    #[Test]
    public function toEntityDataMapsTitleAliasForName(): void
    {
        $data = $this->mapper->toEntityData([
            'title'    => 'Insider-Bedrohung',
            'category' => 'operational',
        ]);

        self::assertSame('Insider-Bedrohung', $data['title']);
    }

    #[Test]
    public function toEntityDataMapsImpactAndLikelihood(): void
    {
        $data = $this->mapper->toEntityData([
            'name'              => 'Risk A',
            'category'          => 'security',
            'inherentImpact'    => '4',
            'inherentLikelihood' => '3',
        ]);

        self::assertSame(4, $data['impact']);
        self::assertSame(3, $data['probability']);
    }

    #[Test]
    public function toEntityDataMapsImpactViaShortAlias(): void
    {
        $data = $this->mapper->toEntityData([
            'name'       => 'Risk A',
            'category'   => 'security',
            'impact'     => '5',
            'likelihood' => '2',
        ]);

        self::assertSame(5, $data['impact']);
        self::assertSame(2, $data['probability']);
    }

    #[Test]
    public function toEntityDataDefaultsTreatmentStrategyToMitigate(): void
    {
        $data = $this->mapper->toEntityData([
            'name'     => 'Risk A',
            'category' => 'security',
        ]);

        self::assertSame('mitigate', $data['treatmentStrategy']);
    }

    #[Test]
    public function toEntityDataMapsReduceToMitigate(): void
    {
        $data = $this->mapper->toEntityData([
            'name'              => 'Risk A',
            'category'          => 'security',
            'treatmentStrategy' => 'reduce',
        ]);

        self::assertSame('mitigate', $data['treatmentStrategy']);
    }

    #[Test]
    public function toEntityDataMapsRequiresDpia(): void
    {
        $data = $this->mapper->toEntityData([
            'name'         => 'DSGVO Risk',
            'category'     => 'compliance',
            'requiresDpia' => 'ja',
        ]);

        self::assertTrue($data['requiresDPIA']);
    }

    // ─── resolveOwnerUser ─────────────────────────────────────────────────────

    #[Test]
    public function resolveOwnerUserReturnsNullWhenOwnerMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $result = $this->mapper->resolveOwnerUser(['name' => 'Risk A', 'category' => 'security'], $tenant);
        self::assertNull($result);
    }

    #[Test]
    public function resolveOwnerUserReturnsNullWhenOwnerIsNotEmail(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $result = $this->mapper->resolveOwnerUser([
            'name'      => 'Risk A',
            'category'  => 'security',
            'riskOwner' => 'Jane Doe',
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
            'name'      => 'Risk A',
            'category'  => 'security',
            'riskOwner' => 'ciso@example.com',
        ], $tenant);

        self::assertSame($user, $result);
    }

    // ─── findExisting ─────────────────────────────────────────────────────────

    #[Test]
    public function findExistingReturnsNullWhenNameMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $result = $this->mapper->findExisting(['category' => 'security'], $tenant);
        self::assertNull($result);
    }

    #[Test]
    public function findExistingReturnsNullWhenCategoryMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $result = $this->mapper->findExisting(['name' => 'Risk A'], $tenant);
        self::assertNull($result);
    }

    #[Test]
    public function findExistingQueriesDatabase(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $risk   = $this->createMock(Risk::class);

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($risk);

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
            'name'     => 'Data Breach',
            'category' => 'security',
        ], $tenant);

        self::assertSame($risk, $result);
    }
}
