<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Entity\Risk;
use App\Validator\Constraint\AcceptedRiskUnchanged;
use App\Validator\Constraint\AcceptedRiskUnchangedValidator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * S12 / ISO 27001 8.3: the residual rating of an accepted risk does not change
 * on REST / MCP writes; the form shows it read-only (ApiEvidenceFieldNamesTest).
 *
 * @extends ConstraintValidatorTestCase<AcceptedRiskUnchangedValidator>
 */
final class AcceptedRiskUnchangedValidatorTest extends ConstraintValidatorTestCase
{
    /** @var array<string, mixed> */
    private array $original = [];

    protected function createValidator(): AcceptedRiskUnchangedValidator
    {
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getOriginalEntityData')->willReturnCallback(fn (): array => $this->original);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        return new AcceptedRiskUnchangedValidator($entityManager);
    }

    #[Test]
    public function aChangedResidualRatingOfAnAcceptedRiskIsRefused(): void
    {
        $this->original = ['formallyAccepted' => true, 'residualProbability' => 2, 'residualImpact' => 3];
        $risk = $this->risk(2, 4);

        $this->validator->validate($risk, new AcceptedRiskUnchanged());

        $this->buildViolation('risk.validation.accepted_residual_frozen')
            ->atPath('property.path.residualImpact')
            ->assertRaised();
    }

    #[Test]
    public function theSameRatingPasses(): void
    {
        $this->original = ['formallyAccepted' => true, 'residualProbability' => 2, 'residualImpact' => 3];

        $this->validator->validate($this->risk(2, 3), new AcceptedRiskUnchanged());

        $this->assertNoViolation();
    }

    #[Test]
    public function aRiskWithoutAcceptanceMayBeReRated(): void
    {
        $this->original = ['formallyAccepted' => false, 'residualProbability' => 2, 'residualImpact' => 3];

        $this->validator->validate($this->risk(5, 5), new AcceptedRiskUnchanged());

        $this->assertNoViolation();
    }

    #[Test]
    public function aNewRiskPasses(): void
    {
        $this->original = ['formallyAccepted' => true, 'residualProbability' => 1, 'residualImpact' => 1];
        $risk = new Risk();
        $risk->setResidualProbability(5);

        $this->validator->validate($risk, new AcceptedRiskUnchanged());

        $this->assertNoViolation();
    }

    #[Test]
    public function theRiskEntityCarriesTheConstraint(): void
    {
        self::assertNotSame([], (new \ReflectionClass(Risk::class))->getAttributes(AcceptedRiskUnchanged::class));
    }

    private function risk(int $probability, int $impact): Risk
    {
        $risk = new Risk();
        (new \ReflectionProperty(Risk::class, 'id'))->setValue($risk, 7);
        $risk->setResidualProbability($probability);
        $risk->setResidualImpact($impact);

        return $risk;
    }
}
