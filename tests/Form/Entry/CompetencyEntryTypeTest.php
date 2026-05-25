<?php

declare(strict_types=1);

namespace App\Tests\Form\Entry;

use App\Form\Entry\CompetencyEntryType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\Test\TypeTestCase;

/**
 * Unit tests for CompetencyEntryType.
 *
 * Covers the array-backed sub-form used by UserType::competencies.
 */
final class CompetencyEntryTypeTest extends TypeTestCase
{
    /**
     * @return list<\Symfony\Component\Form\FormTypeInterface>
     */
    protected function getTypes(): array
    {
        return [new CompetencyEntryType()];
    }

    #[Test]
    public function submitValidDataMapsToAssociativeArray(): void
    {
        $payload = [
            'name' => 'ISO 27001 Lead Auditor',
            'framework' => 'iso27001',
            'level' => 'expert',
            'certifiedAt' => '2025-04-15',
        ];

        $form = $this->factory->create(CompetencyEntryType::class);
        $form->submit($payload);

        self::assertTrue($form->isSynchronized());
        $data = $form->getData();
        self::assertSame('ISO 27001 Lead Auditor', $data['name']);
        self::assertSame('iso27001', $data['framework']);
        self::assertSame('expert', $data['level']);
        self::assertSame('2025-04-15', $data['certifiedAt']);
    }

    #[Test]
    public function dataClassIsNullSoColumnStaysArray(): void
    {
        $type = new CompetencyEntryType();

        $resolver = new \Symfony\Component\OptionsResolver\OptionsResolver();
        $type->configureOptions($resolver);

        $options = $resolver->resolve([]);
        self::assertNull($options['data_class']);
        self::assertSame('user', $options['translation_domain']);
    }

    #[Test]
    public function rejectsUnknownFrameworkChoice(): void
    {
        $form = $this->factory->create(CompetencyEntryType::class);
        $form->submit([
            'name' => 'foo',
            'framework' => 'sox',  // not in allow-list
            'level' => 'basic',
            'certifiedAt' => null,
        ]);

        self::assertFalse($form->isValid());
    }

    #[Test]
    public function rejectsUnknownLevelChoice(): void
    {
        $form = $this->factory->create(CompetencyEntryType::class);
        $form->submit([
            'name' => 'foo',
            'framework' => 'iso27001',
            'level' => 'godmode',
            'certifiedAt' => null,
        ]);

        self::assertFalse($form->isValid());
    }
}
