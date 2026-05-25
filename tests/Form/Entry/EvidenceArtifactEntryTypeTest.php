<?php

declare(strict_types=1);

namespace App\Tests\Form\Entry;

use App\Form\Entry\EvidenceArtifactEntryType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\Test\TypeTestCase;

/**
 * Unit tests for EvidenceArtifactEntryType.
 *
 * Covers the array-backed sub-form used by BCExerciseType::evidenceArtifacts.
 */
final class EvidenceArtifactEntryTypeTest extends TypeTestCase
{
    /**
     * @return list<\Symfony\Component\Form\FormTypeInterface>
     */
    protected function getTypes(): array
    {
        return [new EvidenceArtifactEntryType()];
    }

    #[Test]
    public function submitValidDataMapsToAssociativeArray(): void
    {
        $payload = [
            'type' => 'report',
            'name' => 'DR test signoff',
            'url' => 'https://drive.example.com/dr-test-2026-05.pdf',
            'capturedAt' => '2026-05-15T14:30:00',
        ];

        $form = $this->factory->create(EvidenceArtifactEntryType::class);
        $form->submit($payload);

        self::assertTrue($form->isSynchronized());
        $data = $form->getData();
        self::assertSame('report', $data['type']);
        self::assertSame('DR test signoff', $data['name']);
        self::assertSame('https://drive.example.com/dr-test-2026-05.pdf', $data['url']);
        // DateTimeType single_text returns a space-separated string when input='string'
        self::assertSame('2026-05-15 14:30:00', $data['capturedAt']);
    }

    #[Test]
    public function dataClassIsNullSoColumnStaysArray(): void
    {
        $type = new EvidenceArtifactEntryType();

        $resolver = new \Symfony\Component\OptionsResolver\OptionsResolver();
        $type->configureOptions($resolver);

        $options = $resolver->resolve([]);
        self::assertNull($options['data_class']);
        self::assertSame('bc_exercises', $options['translation_domain']);
    }

    #[Test]
    public function rejectsUnknownArtifactType(): void
    {
        $form = $this->factory->create(EvidenceArtifactEntryType::class);
        $form->submit([
            'type' => 'invented-genre',
            'name' => 'x',
            'url' => '',
            'capturedAt' => null,
        ]);

        self::assertFalse($form->isValid());
    }

    #[Test]
    public function acceptsAllAllowedArtifactTypes(): void
    {
        foreach (['photo', 'log', 'report', 'screenshot', 'video', 'transcript', 'signoff', 'other'] as $allowed) {
            $form = $this->factory->create(EvidenceArtifactEntryType::class);
            $form->submit([
                'type' => $allowed,
                'name' => 'x',
                'url' => '',
                'capturedAt' => null,
            ]);
            self::assertTrue(
                $form->isSynchronized(),
                sprintf('Expected "%s" to be a valid artifact type', $allowed),
            );
            self::assertSame($allowed, $form->getData()['type']);
        }
    }
}
