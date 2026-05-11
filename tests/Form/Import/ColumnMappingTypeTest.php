<?php

declare(strict_types=1);

namespace App\Tests\Form\Import;

use App\Form\Import\ColumnMappingType;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

/**
 * Unit tests for ColumnMappingType (Bulk-Import wizard Step 2).
 *
 * Verifies:
 *   - auto-mappings with confidence ≥ 0.6 are pre-filled
 *   - low-confidence mappings are NOT pre-filled
 *   - the "ignore" option (null) is selectable
 *   - column fields are generated from headers
 */
#[AllowMockObjectsWithoutExpectations]
final class ColumnMappingTypeTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        $type      = new ColumnMappingType();
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        return [
            new PreloadedExtension([$type], []),
            new ValidatorExtension($validator),
        ];
    }

    private function makeFormOptions(
        array $headers = ['Name', 'Typ'],
        array $entityFields = ['name', 'assetType', 'owner'],
        array $autoMappings = [],
    ): array {
        return [
            'headers'       => $headers,
            'entity_fields' => $entityFields,
            'auto_mappings' => $autoMappings,
        ];
    }

    #[Test]
    public function testAutoMappingsArePreFilled(): void
    {
        $options = $this->makeFormOptions(
            headers: ['Name', 'Typ'],
            entityFields: ['name', 'assetType'],
            autoMappings: [
                'Name' => ['target' => 'name',      'confidence' => 1.0],
                'Typ'  => ['target' => 'assetType', 'confidence' => 0.9],
            ],
        );

        $form = $this->factory->create(ColumnMappingType::class, null, $options);

        // column_0 = 'Name' → pre-filled with 'name'
        self::assertSame('name', $form->get('column_0')->getData());
        // column_1 = 'Typ' → pre-filled with 'assetType'
        self::assertSame('assetType', $form->get('column_1')->getData());
    }

    #[Test]
    public function testHeaderWithLowConfidenceIsIgnored(): void
    {
        $options = $this->makeFormOptions(
            headers: ['UnknownColumn'],
            entityFields: ['name', 'assetType'],
            autoMappings: [
                'UnknownColumn' => ['target' => 'name', 'confidence' => 0.3],
            ],
        );

        $form = $this->factory->create(ColumnMappingType::class, null, $options);

        // Confidence 0.3 < 0.6 threshold → should NOT be pre-filled → '' (ignore sentinel)
        self::assertSame('', $form->get('column_0')->getData());
    }

    #[Test]
    public function testIgnoreOptionIsSelectable(): void
    {
        $options = $this->makeFormOptions(
            headers: ['Irrelevant'],
            entityFields: ['name'],
            autoMappings: [],
        );

        $form = $this->factory->create(ColumnMappingType::class, null, $options);

        $form->submit(['column_0' => '']);  // empty string = ignore sentinel

        self::assertTrue($form->isSynchronized());
        // ChoiceType returns '' for the ignore option
        self::assertSame('', $form->get('column_0')->getData());
    }

    #[Test]
    public function testColumnFieldsGeneratedFromHeaders(): void
    {
        $headers = ['Col A', 'Col B', 'Col C'];
        $options = $this->makeFormOptions(headers: $headers);

        $form = $this->factory->create(ColumnMappingType::class, null, $options);

        // Verify one field per header is created
        for ($i = 0; $i < count($headers); $i++) {
            self::assertTrue($form->has('column_' . $i), "Missing field column_{$i}");
        }

        // confirmedMapping hidden field also present
        self::assertTrue($form->has('confirmedMapping'));
    }

    #[Test]
    public function testExactConfidenceThresholdPrefills(): void
    {
        // Exactly 0.6 should be pre-filled (>= threshold)
        $options = $this->makeFormOptions(
            headers: ['Col'],
            entityFields: ['name'],
            autoMappings: [
                'Col' => ['target' => 'name', 'confidence' => 0.6],
            ],
        );

        $form = $this->factory->create(ColumnMappingType::class, null, $options);
        self::assertSame('name', $form->get('column_0')->getData());
    }

    #[Test]
    public function testSubmitMappingOverridesAutoMapping(): void
    {
        $options = $this->makeFormOptions(
            headers: ['Name'],
            entityFields: ['name', 'owner'],
            autoMappings: [
                'Name' => ['target' => 'name', 'confidence' => 1.0],
            ],
        );

        $form = $this->factory->create(ColumnMappingType::class, null, $options);

        // User overrides auto-mapping to 'owner'
        $form->submit(['column_0' => 'owner']);

        self::assertTrue($form->isSynchronized());
        self::assertSame('owner', $form->get('column_0')->getData());
    }
}
