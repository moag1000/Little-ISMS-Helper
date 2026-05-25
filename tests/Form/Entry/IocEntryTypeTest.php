<?php

declare(strict_types=1);

namespace App\Tests\Form\Entry;

use App\Form\Entry\IocEntryType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\Test\TypeTestCase;

/**
 * Unit tests for IocEntryType.
 *
 * Covers the array-backed sub-form used by ThreatIntelligenceType::iocsList.
 */
final class IocEntryTypeTest extends TypeTestCase
{
    /**
     * @return list<class-string>
     */
    protected function getTypes(): array
    {
        return [new IocEntryType()];
    }

    #[Test]
    public function submitValidDataMapsToAssociativeArray(): void
    {
        $payload = [
            'type' => 'ip',
            'value' => '192.0.2.1',
            'confidence' => 4,
        ];

        $form = $this->factory->create(IocEntryType::class);
        $form->submit($payload);

        self::assertTrue($form->isSynchronized());
        self::assertSame($payload, $form->getData());
    }

    #[Test]
    public function submitEmptyPayloadYieldsArrayWithNullsAndEmptyString(): void
    {
        $form = $this->factory->create(IocEntryType::class);
        $form->submit([]);

        self::assertTrue($form->isSynchronized());
        $data = $form->getData();
        self::assertIsArray($data);
        // ChoiceType returns null on no-selection, TextType returns empty string
        self::assertArrayHasKey('type', $data);
        self::assertArrayHasKey('value', $data);
        self::assertArrayHasKey('confidence', $data);
        self::assertNull($data['type']);
        self::assertNull($data['confidence']);
        self::assertNull($data['value']);
    }

    #[Test]
    public function dataClassIsNullSoColumnStaysArray(): void
    {
        $type = new IocEntryType();

        $resolver = new \Symfony\Component\OptionsResolver\OptionsResolver();
        $type->configureOptions($resolver);

        $options = $resolver->resolve([]);
        self::assertNull($options['data_class']);
        self::assertSame('threat', $options['translation_domain']);
    }

    #[Test]
    public function rejectsUnknownIocType(): void
    {
        $form = $this->factory->create(IocEntryType::class);
        $form->submit([
            'type' => 'magic-unicorn-type',
            'value' => 'foo',
            'confidence' => 3,
        ]);

        // Symfony ChoiceType rejects unknown values during transform
        self::assertFalse($form->isValid());
    }
}
