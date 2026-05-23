<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Asset;
use App\Entity\Person;
use App\Entity\Risk;
use App\Entity\User;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Junior-ISB-Audit-2026-05-22 K-05 + M-02 + S-02 — entity-level Assert\Callback
 * coverage.
 *
 *  - K-05: validateOwnerEitherOr — at least one of riskOwner (User) or
 *    riskOwnerPerson (Person) must be set; both null is a violation.
 *  - M-02: validateSubjectBound — at least one of asset / person / location
 *    / supplier must be linked; all-null is a violation (ISO 27001 Cl. 6.1.2 c).
 *  - S-02: validateTreatmentStrategyRequired — once the Risk leaves the
 *    Identified draft state, the treatment strategy must not be null
 *    (ISO 27001 Cl. 6.1.3 a-b).
 */
final class RiskValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /**
     * Minimal Risk fixture that passes every constraint EXCEPT the ones we
     * are deliberately exercising. The other constraints (title, category,
     * description, probability, impact, status) carry their own NotBlank /
     * NotNull / Range checks unrelated to K-05 / M-02.
     */
    private function makeValidRisk(): Risk
    {
        $risk = new Risk();
        $risk->setTitle('TEST-Risk');
        $risk->setCategory('operational');
        $risk->setDescription('TEST description');
        $risk->setProbability(2);
        $risk->setImpact(2);
        $risk->setStatus(RiskStatus::Identified);
        $risk->setTreatmentStrategy(TreatmentStrategy::Mitigate);
        return $risk;
    }

    // ── K-05 coverage ────────────────────────────────────────────────────

    #[Test]
    public function ownerEitherOr_bothNull_yieldsViolationOnRiskOwnerPath(): void
    {
        $risk = $this->makeValidRisk();
        $risk->setAsset(new Asset()); // satisfy M-02 so we test K-05 in isolation

        $violations = $this->validator->validate($risk);

        $paths = [];
        foreach ($violations as $v) {
            $paths[] = $v->getPropertyPath();
        }
        $this->assertContains(
            'riskOwner',
            $paths,
            'Expected exactly one violation at path "riskOwner" when both riskOwner and riskOwnerPerson are null. Got: ' . implode(', ', $paths)
        );
    }

    #[Test]
    public function ownerEitherOr_userSet_personNull_passes(): void
    {
        $risk = $this->makeValidRisk();
        $risk->setAsset(new Asset()); // satisfy M-02
        $risk->setRiskOwner(new User());

        $violations = $this->validator->validate($risk);

        $paths = [];
        foreach ($violations as $v) {
            $paths[] = $v->getPropertyPath();
        }
        $this->assertNotContains(
            'riskOwner',
            $paths,
            'Expected NO violation at path "riskOwner" when User is set. Got: ' . implode(', ', $paths)
        );
    }

    #[Test]
    public function ownerEitherOr_userNull_personSet_passes(): void
    {
        $risk = $this->makeValidRisk();
        $risk->setAsset(new Asset()); // satisfy M-02
        $risk->setRiskOwnerPerson(new Person());

        $violations = $this->validator->validate($risk);

        $paths = [];
        foreach ($violations as $v) {
            $paths[] = $v->getPropertyPath();
        }
        $this->assertNotContains(
            'riskOwner',
            $paths,
            'Expected NO violation at path "riskOwner" when Person is set. Got: ' . implode(', ', $paths)
        );
    }

    // ── M-02 coverage ────────────────────────────────────────────────────

    #[Test]
    public function subjectBound_allNull_yieldsViolationOnAssetPath(): void
    {
        $risk = $this->makeValidRisk();
        $risk->setRiskOwner(new User()); // satisfy K-05

        $violations = $this->validator->validate($risk);

        $paths = [];
        foreach ($violations as $v) {
            $paths[] = $v->getPropertyPath();
        }
        $this->assertContains(
            'asset',
            $paths,
            'Expected violation at path "asset" when no subject (asset/person/location/supplier) is bound. Got: ' . implode(', ', $paths)
        );
    }

    #[Test]
    public function subjectBound_assetSet_passes(): void
    {
        $risk = $this->makeValidRisk();
        $risk->setRiskOwner(new User()); // satisfy K-05
        $risk->setAsset(new Asset());

        $violations = $this->validator->validate($risk);

        $paths = [];
        foreach ($violations as $v) {
            $paths[] = $v->getPropertyPath();
        }
        $this->assertNotContains(
            'asset',
            $paths,
            'Expected NO violation at path "asset" when an Asset is bound. Got: ' . implode(', ', $paths)
        );
    }

    // ── S-02 coverage ────────────────────────────────────────────────────

    #[Test]
    public function treatmentStrategy_draftIdentifiedAndNull_passes(): void
    {
        $risk = $this->makeValidRisk();
        $risk->setRiskOwner(new User()); // satisfy K-05
        $risk->setAsset(new Asset());    // satisfy M-02
        $risk->setStatus(RiskStatus::Identified);
        $risk->setTreatmentStrategy(null); // draft Risk → strategy may be empty

        $violations = $this->validator->validate($risk);

        $paths = [];
        foreach ($violations as $v) {
            $paths[] = $v->getPropertyPath();
        }
        $this->assertNotContains(
            'treatmentStrategy',
            $paths,
            'Expected NO violation at path "treatmentStrategy" when Risk is in Identified (draft) state. Got: ' . implode(', ', $paths)
        );
    }

    #[Test]
    public function treatmentStrategy_assessedAndNull_yieldsViolation(): void
    {
        $risk = $this->makeValidRisk();
        $risk->setRiskOwner(new User()); // satisfy K-05
        $risk->setAsset(new Asset());    // satisfy M-02
        $risk->setStatus(RiskStatus::Assessed);
        $risk->setTreatmentStrategy(null);

        $violations = $this->validator->validate($risk);

        $paths = [];
        foreach ($violations as $v) {
            $paths[] = $v->getPropertyPath();
        }
        $this->assertContains(
            'treatmentStrategy',
            $paths,
            'Expected violation at path "treatmentStrategy" when Risk is Assessed and strategy is null (ISO 27001 Cl. 6.1.3 a-b). Got: ' . implode(', ', $paths)
        );
    }

    #[Test]
    public function treatmentStrategy_inTreatmentAndNull_yieldsViolation(): void
    {
        $risk = $this->makeValidRisk();
        $risk->setRiskOwner(new User()); // satisfy K-05
        $risk->setAsset(new Asset());    // satisfy M-02
        $risk->setStatus(RiskStatus::InTreatment);
        $risk->setTreatmentStrategy(null);

        $violations = $this->validator->validate($risk);

        $paths = [];
        foreach ($violations as $v) {
            $paths[] = $v->getPropertyPath();
        }
        $this->assertContains(
            'treatmentStrategy',
            $paths,
            'Expected violation at path "treatmentStrategy" when Risk is InTreatment and strategy is null. Got: ' . implode(', ', $paths)
        );
    }

    #[Test]
    public function treatmentStrategy_acceptedAndNull_yieldsViolation(): void
    {
        $risk = $this->makeValidRisk();
        $risk->setRiskOwner(new User()); // satisfy K-05
        $risk->setAsset(new Asset());    // satisfy M-02
        $risk->setStatus(RiskStatus::Accepted);
        $risk->setTreatmentStrategy(null);

        $violations = $this->validator->validate($risk);

        $paths = [];
        foreach ($violations as $v) {
            $paths[] = $v->getPropertyPath();
        }
        $this->assertContains(
            'treatmentStrategy',
            $paths,
            'Expected violation at path "treatmentStrategy" when Risk is Accepted and strategy is null. Got: ' . implode(', ', $paths)
        );
    }

    #[Test]
    public function treatmentStrategy_assessedWithMitigate_passes(): void
    {
        $risk = $this->makeValidRisk();
        $risk->setRiskOwner(new User()); // satisfy K-05
        $risk->setAsset(new Asset());    // satisfy M-02
        $risk->setStatus(RiskStatus::Assessed);
        $risk->setTreatmentStrategy(TreatmentStrategy::Mitigate);

        $violations = $this->validator->validate($risk);

        $paths = [];
        foreach ($violations as $v) {
            $paths[] = $v->getPropertyPath();
        }
        $this->assertNotContains(
            'treatmentStrategy',
            $paths,
            'Expected NO violation at path "treatmentStrategy" when Risk is Assessed and strategy is Mitigate. Got: ' . implode(', ', $paths)
        );
    }
}
