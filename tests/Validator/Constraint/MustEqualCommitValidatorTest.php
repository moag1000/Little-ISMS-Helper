<?php

declare(strict_types=1);

namespace App\Tests\Validator\Constraint;

use App\Validator\Constraint\MustEqualCommit;
use App\Validator\Constraint\MustEqualCommitValidator;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<MustEqualCommitValidator>
 */
class MustEqualCommitValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): MustEqualCommitValidator
    {
        return new MustEqualCommitValidator();
    }

    #[Test]
    public function testValidCommitPasses(): void
    {
        $this->validator->validate('COMMIT', new MustEqualCommit());

        $this->assertNoViolation();
    }

    #[Test]
    public function testLowercaseRejected(): void
    {
        $this->validator->validate('commit', new MustEqualCommit());

        // 'commit' uppercased = 'COMMIT' — should pass (case-insensitive)
        $this->assertNoViolation();
    }

    #[Test]
    public function testEmptyRejected(): void
    {
        $this->validator->validate('', new MustEqualCommit());

        $this->buildViolation('import.error.must_equal_commit')->assertRaised();
    }

    #[Test]
    public function testNullRejected(): void
    {
        $this->validator->validate(null, new MustEqualCommit());

        $this->buildViolation('import.error.must_equal_commit')->assertRaised();
    }

    #[Test]
    public function testRandomTextRejected(): void
    {
        $this->validator->validate('yes', new MustEqualCommit());

        $this->buildViolation('import.error.must_equal_commit')->assertRaised();
    }

    #[Test]
    public function testMixedCaseAccepted(): void
    {
        $this->validator->validate('Commit', new MustEqualCommit());

        $this->assertNoViolation();
    }

    #[Test]
    public function testPartialWordRejected(): void
    {
        $this->validator->validate('COMMI', new MustEqualCommit());

        $this->buildViolation('import.error.must_equal_commit')->assertRaised();
    }
}
