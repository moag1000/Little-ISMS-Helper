<?php

declare(strict_types=1);

namespace App\Tests\Form\Import;

use App\Form\Import\PreviewConfirmType;
use App\Validator\Constraint\MustEqualCommit;
use App\Validator\Constraint\MustEqualCommitValidator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

/**
 * Unit tests for PreviewConfirmType (Bulk-Import wizard Step 3).
 *
 * Verifies:
 *   - "COMMIT" (any case) passes validation
 *   - other text is rejected by MustEqualCommit constraint
 *   - skipOnError is optional with default false
 *   - batchId hidden field is present
 */
#[AllowMockObjectsWithoutExpectations]
final class PreviewConfirmTypeTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        $type = new PreviewConfirmType();

        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        return [
            new PreloadedExtension([$type], []),
            new ValidatorExtension($validator),
        ];
    }

    #[Test]
    public function testValidConfirmTextCommitPasses(): void
    {
        $form = $this->factory->create(PreviewConfirmType::class);

        $form->submit([
            'skipOnError' => false,
            'confirmText' => 'COMMIT',
            'batchId'     => 42,
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid(), implode('; ', array_map(
            static fn($e) => $e->getMessage(),
            iterator_to_array($form->getErrors(true)),
        )));
    }

    #[Test]
    public function testLowercaseCommitAlsoPasses(): void
    {
        $form = $this->factory->create(PreviewConfirmType::class);

        $form->submit([
            'skipOnError' => false,
            'confirmText' => 'commit',
            'batchId'     => 1,
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid(), 'Lowercase "commit" must pass (case-insensitive)');
    }

    #[Test]
    public function testWrongConfirmTextFails(): void
    {
        $form = $this->factory->create(PreviewConfirmType::class);

        $form->submit([
            'skipOnError' => false,
            'confirmText' => 'yes',
            'batchId'     => 1,
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid(), 'Wrong confirm text should be rejected');
    }

    #[Test]
    public function testEmptyConfirmTextFails(): void
    {
        $form = $this->factory->create(PreviewConfirmType::class);

        $form->submit([
            'skipOnError' => false,
            'confirmText' => '',
            'batchId'     => 1,
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid(), 'Empty confirm text should be rejected');
    }

    #[Test]
    public function testSkipOnErrorOptional(): void
    {
        $form = $this->factory->create(PreviewConfirmType::class);

        // Submit without skipOnError (unchecked checkbox in real POST)
        $form->submit([
            'confirmText' => 'COMMIT',
            'batchId'     => 1,
        ]);

        self::assertTrue($form->isSynchronized());
        // Missing checkbox = false (unchecked)
        self::assertFalse($form->get('skipOnError')->getData());
    }

    #[Test]
    public function testSkipOnErrorTrue(): void
    {
        $form = $this->factory->create(PreviewConfirmType::class);

        $form->submit([
            'skipOnError' => true,
            'confirmText' => 'COMMIT',
            'batchId'     => 5,
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid());
        self::assertTrue($form->get('skipOnError')->getData());
    }

    #[Test]
    public function testFormHasExpectedFields(): void
    {
        $form = $this->factory->create(PreviewConfirmType::class);

        self::assertTrue($form->has('skipOnError'));
        self::assertTrue($form->has('confirmText'));
        self::assertTrue($form->has('batchId'));
    }
}
