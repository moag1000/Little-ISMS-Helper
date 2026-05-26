<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\BcExerciseSuccessCriteriaType;
use App\Form\DataTransformer\SuccessCriteriaShapeTransformer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\Test\TypeTestCase;

/**
 * Unit tests for BcExerciseSuccessCriteriaType (Bucket 5 item 5.3).
 *
 * Covers both production shapes co-existing on BCExercise.successCriteria:
 *   Shape A (rich list of {criterion,target,actual,met} objects)
 *   Shape B (legacy flat map of bool flags)
 */
final class BcExerciseSuccessCriteriaTypeTest extends TypeTestCase
{
    /**
     * @return list<\Symfony\Component\Form\FormTypeInterface>
     */
    protected function getTypes(): array
    {
        return [new BcExerciseSuccessCriteriaType()];
    }

    #[Test]
    public function shapeARoundTripPreservesAllFields(): void
    {
        $shapeA = [
            ['criterion' => 'RTO eingehalten', 'target' => '4h', 'actual' => '3h', 'met' => 'met'],
            ['criterion' => 'Comms funktional', 'target' => 'ja', 'actual' => 'ja', 'met' => 'met'],
        ];

        $transformer = new SuccessCriteriaShapeTransformer();
        $viewModel = $transformer->transform($shapeA);

        self::assertJson($viewModel);
        $decoded = json_decode($viewModel, true);
        self::assertCount(2, $decoded);
        self::assertSame('RTO eingehalten', $decoded[0]['criterion']);
        self::assertSame('4h', $decoded[0]['target']);
        self::assertSame('3h', $decoded[0]['actual']);
        self::assertSame('met', $decoded[0]['met']);

        $reversed = $transformer->reverseTransform($viewModel);
        self::assertCount(2, $reversed);
        self::assertSame($shapeA[0]['criterion'], $reversed[0]['criterion']);
    }

    #[Test]
    public function shapeBLegacyFlatMapIsCoercedToRichListForBuilder(): void
    {
        $shapeB = [
            'rtoMet' => true,
            'rpoMet' => true,
            'communicationEffective' => false,
            'teamPrepared' => true,
        ];

        $transformer = new SuccessCriteriaShapeTransformer();
        $viewModel = $transformer->transform($shapeB);

        self::assertJson($viewModel);
        $decoded = json_decode($viewModel, true);
        self::assertCount(4, $decoded);
        self::assertSame('rtoMet', $decoded[0]['criterion']);
        self::assertSame('met', $decoded[0]['met']);
        self::assertSame('communicationEffective', $decoded[2]['criterion']);
        self::assertSame('not_met', $decoded[2]['met']);
    }

    #[Test]
    public function shapeBLegacyFlatMapSurvivesUneditedRoundTrip(): void
    {
        $shapeB = ['rtoMet' => true, 'rpoMet' => false];
        $transformer = new SuccessCriteriaShapeTransformer();

        // Simulate "no edit": user submits the entity-shape unchanged.
        // The form theme always emits Shape A, but if the underlying JSON
        // hits reverseTransform untouched (e.g. another API consumer), the
        // legacy assoc shape must survive — keys != list trigger BC mode.
        $reversed = $transformer->reverseTransform(json_encode($shapeB));

        self::assertSame($shapeB, $reversed);
    }

    #[Test]
    public function emptyJsonMapsToNull(): void
    {
        $transformer = new SuccessCriteriaShapeTransformer();
        self::assertNull($transformer->reverseTransform(''));
        self::assertNull($transformer->reverseTransform('   '));
        self::assertNull($transformer->reverseTransform(null));
        self::assertNull($transformer->reverseTransform('[]'));
    }

    #[Test]
    public function nullEntityValueRendersEmptyTextarea(): void
    {
        $transformer = new SuccessCriteriaShapeTransformer();
        self::assertSame('', $transformer->transform(null));
        self::assertSame('', $transformer->transform([]));
    }

    #[Test]
    public function invalidJsonThrowsTransformationFailedException(): void
    {
        $this->expectException(\Symfony\Component\Form\Exception\TransformationFailedException::class);

        $transformer = new SuccessCriteriaShapeTransformer();
        $transformer->reverseTransform('{not-valid-json');
    }

    #[Test]
    public function topLevelScalarJsonRejectedAsTransformationFailure(): void
    {
        $this->expectException(\Symfony\Component\Form\Exception\TransformationFailedException::class);

        $transformer = new SuccessCriteriaShapeTransformer();
        $transformer->reverseTransform('"just a string"');
    }

    #[Test]
    public function entirelyEmptyRowsAreDroppedOnReverse(): void
    {
        $payload = json_encode([
            ['criterion' => '', 'target' => '', 'actual' => '', 'met' => 'unknown'],
            ['criterion' => 'Valid', 'target' => '1h', 'actual' => '1h', 'met' => 'met'],
        ]);

        $transformer = new SuccessCriteriaShapeTransformer();
        $reversed = $transformer->reverseTransform($payload);

        self::assertCount(1, $reversed);
        self::assertSame('Valid', $reversed[0]['criterion']);
    }

    #[Test]
    public function submittingJsonRoundTripsThroughForm(): void
    {
        $shapeA = [
            ['criterion' => 'RTO', 'target' => '4h', 'actual' => '3h', 'met' => 'met'],
        ];
        $payload = json_encode($shapeA);

        $form = $this->factory->create(BcExerciseSuccessCriteriaType::class);
        $form->submit($payload);

        self::assertTrue($form->isSynchronized());
        $data = $form->getData();
        self::assertIsArray($data);
        self::assertCount(1, $data);
        self::assertSame('RTO', $data[0]['criterion']);
    }

    #[Test]
    public function unknownMetValueDefaultsToUnknownOnSanitisation(): void
    {
        $payload = json_encode([
            ['criterion' => 'X', 'target' => '', 'actual' => '', 'met' => 'maybe-someday'],
        ]);

        $transformer = new SuccessCriteriaShapeTransformer();
        $reversed = $transformer->reverseTransform($payload);

        self::assertSame('unknown', $reversed[0]['met']);
    }
}
