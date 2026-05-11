<?php

declare(strict_types=1);

namespace App\Tests\Service\Import\Mapper;

use App\Entity\Control;
use App\Entity\Tenant;
use App\Repository\ControlRepository;
use App\Service\Import\Mapper\ControlMapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class ControlMapperTest extends TestCase
{
    private MockObject $em;
    private MockObject $controlRepository;
    private ControlMapper $mapper;

    protected function setUp(): void
    {
        $this->em                = $this->createMock(EntityManagerInterface::class);
        $this->controlRepository = $this->createMock(ControlRepository::class);

        $this->mapper = new ControlMapper(
            $this->em,
            $this->controlRepository,
        );
    }

    #[Test]
    public function supportsEntityTypeReturnsTrueForControl(): void
    {
        self::assertTrue($this->mapper->supportsEntityType('Control'));
    }

    #[Test]
    public function supportsEntityTypeReturnsFalseForOthers(): void
    {
        self::assertFalse($this->mapper->supportsEntityType('Asset'));
        self::assertFalse($this->mapper->supportsEntityType('Supplier'));
    }

    #[Test]
    public function validateRequiresIdentifier(): void
    {
        $result = $this->mapper->validate([]);
        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('identifier', $result['errors'][0]);
    }

    #[Test]
    public function validateRejectsInvalidIdentifierFormat(): void
    {
        $result = $this->mapper->validate(['identifier' => 'ABC']);
        self::assertNotEmpty($result['errors']);
    }

    #[Test]
    public function validateAcceptsShortFormIdentifier(): void
    {
        $result = $this->mapper->validate(['identifier' => '5.1']);
        self::assertEmpty($result['errors']);
    }

    #[Test]
    public function validateAcceptsAnnexALongFormIdentifier(): void
    {
        $result = $this->mapper->validate(['identifier' => 'A.5.1']);
        self::assertEmpty($result['errors']);
    }

    #[Test]
    public function validateAcceptsThreeLevelIdentifier(): void
    {
        $result = $this->mapper->validate(['identifier' => '8.1.1']);
        self::assertEmpty($result['errors']);
    }

    #[Test]
    public function validateEmitsWarningForUnknownApplicability(): void
    {
        $result = $this->mapper->validate([
            'identifier'    => '5.1',
            'applicability' => 'unknown',
        ]);
        self::assertEmpty($result['errors']);
        self::assertNotEmpty($result['warnings']);
    }

    #[Test]
    public function validatePassesKnownApplicability(): void
    {
        foreach (['applicable', 'not_applicable', 'not_determined'] as $value) {
            $result = $this->mapper->validate([
                'identifier'    => '5.1',
                'applicability' => $value,
            ]);
            self::assertEmpty($result['errors'], "Expected no errors for applicability=$value");
        }
    }

    #[Test]
    public function toEntityDataNormalisesAnnexAPrefix(): void
    {
        $data = $this->mapper->toEntityData(['identifier' => 'A.5.1']);
        self::assertSame('5.1', $data['controlId']);
    }

    #[Test]
    public function toEntityDataKeepsShortFormIdentifier(): void
    {
        $data = $this->mapper->toEntityData(['identifier' => '8.3']);
        self::assertSame('8.3', $data['controlId']);
    }

    #[Test]
    public function toEntityDataMapsApplicableToTrue(): void
    {
        $data = $this->mapper->toEntityData([
            'identifier'    => '5.1',
            'applicability' => 'applicable',
        ]);
        self::assertTrue($data['applicable']);
    }

    #[Test]
    public function toEntityDataMapsNotApplicableToFalse(): void
    {
        $data = $this->mapper->toEntityData([
            'identifier'    => '5.1',
            'applicability' => 'not_applicable',
        ]);
        self::assertFalse($data['applicable']);
    }

    #[Test]
    public function toEntityDataMapsNotDeterminedToNull(): void
    {
        $data = $this->mapper->toEntityData([
            'identifier'    => '5.1',
            'applicability' => 'not_determined',
        ]);
        self::assertNull($data['applicable']);
    }

    #[Test]
    public function toEntityDataMapsTitle(): void
    {
        $data = $this->mapper->toEntityData([
            'identifier' => '5.1',
            'title'      => 'Policies for information security',
        ]);
        self::assertSame('Policies for information security', $data['name']);
    }

    #[Test]
    public function toEntityDataMapsJustification(): void
    {
        $data = $this->mapper->toEntityData([
            'identifier'    => '5.1',
            'applicability' => 'not_applicable',
            'justification' => 'Out of scope for SME.',
        ]);
        self::assertSame('Out of scope for SME.', $data['justification']);
    }

    #[Test]
    public function findExistingReturnNullWhenIdentifierMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        self::assertNull($this->mapper->findExisting([], $tenant));
    }

    #[Test]
    public function findExistingUsesNormalisedControlId(): void
    {
        $tenant  = $this->createMock(Tenant::class);
        $control = $this->createMock(Control::class);

        // Expects lookup with normalised "5.1" (not "A.5.1")
        $this->controlRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['controlId' => '5.1', 'tenant' => $tenant])
            ->willReturn($control);

        $result = $this->mapper->findExisting(['identifier' => 'A.5.1'], $tenant);
        self::assertSame($control, $result);
    }

    #[Test]
    public function findExistingReturnsNullWhenNoMatch(): void
    {
        $tenant = $this->createMock(Tenant::class);

        $this->controlRepository
            ->method('findOneBy')
            ->willReturn(null);

        $result = $this->mapper->findExisting(['identifier' => '99.99'], $tenant);
        self::assertNull($result);
    }
}
