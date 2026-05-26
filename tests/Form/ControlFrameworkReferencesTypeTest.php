<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\ControlFrameworkReferencesType;
use App\Form\DataTransformer\FrameworkReferencesTransformer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\Test\TypeTestCase;

/**
 * Unit tests for ControlFrameworkReferencesType (Bucket 5 item 5.5).
 *
 * Backing entity shape: array<framework_slug, list<reference_id>>
 * View / submit shape : array<slug, comma-separated-csv>
 */
final class ControlFrameworkReferencesTypeTest extends TypeTestCase
{
    /**
     * @return list<\Symfony\Component\Form\FormTypeInterface>
     */
    protected function getTypes(): array
    {
        return [new ControlFrameworkReferencesType()];
    }

    #[Test]
    public function knownFrameworkSlugsAreAllRegisteredAsChildren(): void
    {
        $form = $this->factory->create(ControlFrameworkReferencesType::class);

        foreach (ControlFrameworkReferencesType::KNOWN_FRAMEWORKS as $slug) {
            self::assertTrue(
                $form->has($slug),
                sprintf('Expected child form for framework slug "%s"', $slug),
            );
        }
    }

    #[Test]
    public function entityShapeArrayOfListsTransformsToCsvPerSlug(): void
    {
        $entity = [
            'iso27001' => ['A.5.1', 'A.5.2'],
            'bsi' => ['ORP.1.A1'],
            'nist' => ['AC-1', 'AC-2', 'AC-3'],
        ];

        $transformer = new FrameworkReferencesTransformer();
        $view = $transformer->transform($entity);

        self::assertSame('A.5.1,A.5.2', $view['iso27001']);
        self::assertSame('ORP.1.A1', $view['bsi']);
        self::assertSame('AC-1,AC-2,AC-3', $view['nist']);
    }

    #[Test]
    public function csvPerSlugReversesBackToArrayOfLists(): void
    {
        $view = [
            'iso27001' => 'A.5.1,A.5.2',
            'bsi' => 'ORP.1.A1',
            'nist' => 'AC-1,AC-2',
            'dora' => '',     // empty slug must be dropped
        ];

        $transformer = new FrameworkReferencesTransformer();
        $entity = $transformer->reverseTransform($view);

        self::assertSame(['A.5.1', 'A.5.2'], $entity['iso27001']);
        self::assertSame(['ORP.1.A1'], $entity['bsi']);
        self::assertSame(['AC-1', 'AC-2'], $entity['nist']);
        self::assertArrayNotHasKey('dora', $entity);
    }

    #[Test]
    public function whitespaceAroundRefsIsTrimmed(): void
    {
        $view = ['iso27001' => '  A.5.1 ,  A.5.2  '];

        $transformer = new FrameworkReferencesTransformer();
        $entity = $transformer->reverseTransform($view);

        self::assertSame(['A.5.1', 'A.5.2'], $entity['iso27001']);
    }

    #[Test]
    public function entirelyEmptySubmissionMapsToNull(): void
    {
        $transformer = new FrameworkReferencesTransformer();
        self::assertNull($transformer->reverseTransform([]));
        self::assertNull($transformer->reverseTransform(null));
        self::assertNull($transformer->reverseTransform([
            'iso27001' => '',
            'bsi' => '   ',
        ]));
    }

    #[Test]
    public function nullEntityValueTransformsToEmptyArray(): void
    {
        $transformer = new FrameworkReferencesTransformer();
        self::assertSame([], $transformer->transform(null));
        self::assertSame([], $transformer->transform([]));
    }

    #[Test]
    public function legacyStringPerSlugStillSupportedOnTransform(): void
    {
        // Tolerate legacy rows where someone stored a string instead of a list.
        $entity = ['iso27001' => 'A.5.1'];

        $transformer = new FrameworkReferencesTransformer();
        $view = $transformer->transform($entity);

        self::assertSame('A.5.1', $view['iso27001']);
    }

    #[Test]
    public function unknownCustomSlugIsSurfacedDynamicallyOnPreSetData(): void
    {
        $entityData = [
            'iso27001' => ['A.5.1'],
            'tenant_custom_framework' => ['CUST-1', 'CUST-2'],
        ];

        $form = $this->factory->create(ControlFrameworkReferencesType::class);
        $form->setData($entityData);

        self::assertTrue($form->has('tenant_custom_framework'),
            'Custom slug must be auto-registered via PRE_SET_DATA');
    }

    #[Test]
    public function unknownCustomSlugSurfacedOnPreSubmit(): void
    {
        $form = $this->factory->create(ControlFrameworkReferencesType::class);
        $form->submit([
            'iso27001' => 'A.5.1',
            'tenant_custom' => 'CUST-1,CUST-2',
        ]);

        self::assertTrue($form->isSynchronized());
        $data = $form->getData();
        self::assertSame(['A.5.1'], $data['iso27001']);
        self::assertSame(['CUST-1', 'CUST-2'], $data['tenant_custom']);
    }

    #[Test]
    public function fullRoundTripThroughFormPreservesValues(): void
    {
        $form = $this->factory->create(ControlFrameworkReferencesType::class);
        $form->submit([
            'iso27001' => 'A.5.1,A.5.2',
            'bsi' => 'ORP.1.A1',
            'nist' => '',  // empty values dropped
        ]);

        self::assertTrue($form->isSynchronized());
        $data = $form->getData();
        self::assertSame(['A.5.1', 'A.5.2'], $data['iso27001']);
        self::assertSame(['ORP.1.A1'], $data['bsi']);
        self::assertArrayNotHasKey('nist', $data);
    }

    #[Test]
    public function existingEntityWithCustomSlugRoundTripsCleanly(): void
    {
        $entityData = [
            'iso27001' => ['A.5.1'],
            'tenant_custom' => ['CUST-1'],
        ];

        $form = $this->factory->create(ControlFrameworkReferencesType::class);
        $form->setData($entityData);

        // After setData, view should show CSVs.
        $view = $form->createView();
        self::assertSame('A.5.1', $view->children['iso27001']->vars['value']);
        self::assertSame('CUST-1', $view->children['tenant_custom']->vars['value']);

        // Now submit a tweaked version and verify it reverses cleanly.
        $form->submit([
            'iso27001' => 'A.5.1,A.5.3',
            'tenant_custom' => 'CUST-1,CUST-2',
        ]);

        self::assertTrue($form->isSynchronized());
        $data = $form->getData();
        self::assertSame(['A.5.1', 'A.5.3'], $data['iso27001']);
        self::assertSame(['CUST-1', 'CUST-2'], $data['tenant_custom']);
    }
}
