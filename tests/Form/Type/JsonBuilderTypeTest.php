<?php

declare(strict_types=1);

namespace App\Tests\Form\Type;

use App\Form\Type\JsonBuilderType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Round-trip + server-side shape validation of the generic JSON builder type.
 * Runs against the real form factory so the JsonShape validator is wired.
 */
final class JsonBuilderTypeTest extends KernelTestCase
{
    private const LIST_FIELDS = [
        ['key' => 'action', 'type' => 'text', 'required' => true],
        ['key' => 'deadline', 'type' => 'date'],
        ['key' => 'status', 'type' => 'select', 'choices' => ['Open' => 'open', 'Done' => 'done']],
        ['key' => 'count', 'type' => 'number'],
    ];

    /**
     * @param array<string, mixed> $options
     */
    private function form(array $options, mixed $data = null): FormInterface
    {
        self::bootKernel();
        /** @var FormFactoryInterface $factory */
        $factory = self::getContainer()->get('form.factory');

        return $factory->createBuilder(FormType::class, ['value' => $data], ['csrf_protection' => false])
            ->add('value', JsonBuilderType::class, $options + ['required' => false])
            ->getForm();
    }

    #[Test]
    public function listValueRoundTripsAndKeepsUndeclaredKeys(): void
    {
        $data = [['action' => 'Patch firewall', 'deadline' => '2026-12-01', 'status' => 'open', 'legacy_note' => 'kept']];
        $form = $this->form(['shape' => 'list', 'fields' => self::LIST_FIELDS], $data);

        self::assertSame($data, json_decode($form->createView()['value']->vars['value'], true));

        $form->submit(['value' => json_encode($data)]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame($data, $form->get('value')->getData());
    }

    #[Test]
    public function legacyScalarListEntriesAreKeptAndDoNotBlockTheSave(): void
    {
        $data = ['Call the DPO', ['action' => 'x'], 42];
        $form = $this->form(['shape' => 'list', 'fields' => self::LIST_FIELDS], $data);
        $form->submit(['value' => json_encode($data)]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame($data, $form->get('value')->getData());
    }

    #[Test]
    public function emptySubmissionStoresNull(): void
    {
        $form = $this->form(['shape' => 'list', 'fields' => self::LIST_FIELDS], [['action' => 'x']]);
        $form->submit(['value' => '']);

        self::assertTrue($form->isValid());
        self::assertNull($form->get('value')->getData());
    }

    #[Test]
    public function missingRequiredFieldIsRejected(): void
    {
        $form = $this->form(['shape' => 'list', 'fields' => self::LIST_FIELDS]);
        $form->submit(['value' => json_encode([['deadline' => '2026-01-01']])]);

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('value')->getErrors());
    }

    #[Test]
    public function typedFieldsAreValidatedServerSide(): void
    {
        foreach ([
            [['action' => 'a', 'deadline' => '01.12.2026']],      // not Y-m-d
            [['action' => 'a', 'status' => 'archived']],          // not a choice
            [['action' => 'a', 'count' => 'three']],              // not a number
            ['action' => 'a'],                                    // object instead of list
            [['a', 'b']],                                         // list of lists
        ] as $bad) {
            $form = $this->form(['shape' => 'list', 'fields' => self::LIST_FIELDS]);
            $form->submit(['value' => json_encode($bad)]);
            self::assertFalse($form->isValid(), 'accepted: ' . json_encode($bad));
        }
    }

    #[Test]
    public function invalidJsonIsATransformationError(): void
    {
        $form = $this->form(['shape' => 'list', 'fields' => self::LIST_FIELDS]);
        $form->submit(['value' => '[{"action":']);

        self::assertFalse($form->get('value')->isSynchronized());
        self::assertFalse($form->isValid());
    }

    #[Test]
    public function mapShapeValidatesEntriesAndKeys(): void
    {
        $fields = [
            ['key' => 'name', 'type' => 'text', 'required' => true],
            ['key' => 'email', 'type' => 'email'],
        ];
        $good = ['BSI' => ['name' => 'Bundesamt', 'email' => 'meldestelle@bsi.bund.de']];
        $form = $this->form(['shape' => 'map', 'fields' => $fields], $good);
        $form->submit(['value' => json_encode($good)]);
        self::assertTrue($form->isValid());
        self::assertSame($good, $form->get('value')->getData());

        $form = $this->form(['shape' => 'map', 'fields' => $fields]);
        $form->submit(['value' => json_encode(['BSI' => ['name' => 'B', 'email' => 'not-an-email']])]);
        self::assertFalse($form->isValid());

        $form = $this->form(['shape' => 'map', 'fields' => $fields]);
        $form->submit(['value' => json_encode(['' => ['name' => 'B']])]);
        self::assertFalse($form->isValid());
    }

    #[Test]
    public function scalarMapAndObjectShapes(): void
    {
        $scalar = ['applicant_data' => '6 months', 'payroll' => '10 years'];
        $form = $this->form(['shape' => 'map', 'scalar_value' => true, 'fields' => [['key' => 'period', 'type' => 'text', 'required' => true]]], $scalar);
        $form->submit(['value' => json_encode($scalar)]);
        self::assertTrue($form->isValid());
        self::assertSame($scalar, $form->get('value')->getData());

        $object = ['recipients' => ['a@example.com'], 'timeout' => 10, 'delivery_mode' => 'digest_daily'];
        $form = $this->form(['shape' => 'object', 'hidden_keys' => ['delivery_mode'], 'fields' => [
            ['key' => 'recipients', 'type' => 'tags', 'item_type' => 'email'],
            ['key' => 'timeout', 'type' => 'number'],
        ]], $object);
        $form->submit(['value' => json_encode($object)]);
        self::assertTrue($form->isValid());
        self::assertSame($object, $form->get('value')->getData());

        $form = $this->form(['shape' => 'object', 'fields' => [['key' => 'recipients', 'type' => 'tags', 'item_type' => 'email']]]);
        $form->submit(['value' => json_encode(['recipients' => ['nope']])]);
        self::assertFalse($form->isValid());
    }

    #[Test]
    public function viewCarriesTheSchemaForTheBuilder(): void
    {
        $form = $this->form(['shape' => 'map', 'hidden_keys' => ['_global'], 'fields' => [['key' => 'email', 'type' => 'bool']]]);
        $vars = $form->createView()['value']->vars;

        self::assertContains('json_builder', $vars['block_prefixes']);
        self::assertSame('map', $vars['json_builder']['shape']);
        self::assertSame(['_global'], $vars['json_builder']['hidden_keys']);
    }

    #[Test]
    public function unknownFieldTypeIsAProgrammingError(): void
    {
        $this->expectException(\Symfony\Component\OptionsResolver\Exception\ExceptionInterface::class);
        $this->form(['shape' => 'list', 'fields' => [['key' => 'x', 'type' => 'json']]]);
    }
}
