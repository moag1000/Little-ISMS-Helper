<?php

declare(strict_types=1);

namespace App\Tests\Form\Notification;

use App\Entity\Notification\NotificationRule;
use App\Form\Notification\NotificationRuleType;
use App\Service\Notification\NotificationRuleEvaluator;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Twig\Environment;

/**
 * The notification-rule form must write conditions in exactly the format
 * NotificationRuleEvaluator reads (`field` / `op` / `value`, optional
 * `{"_logic": "or"}`) — the old form wrote `operator`, so no condition ever
 * matched — and the fa-condition-builder must render it as chip rows.
 */
final class NotificationRuleConditionsTest extends KernelTestCase
{
    #[Test]
    public function submittedRowsAreStoredInEvaluatorFormatAndMatch(): void
    {
        $rule = new NotificationRule();
        $form = $this->form($rule);
        $form->submit([
            'name' => 'High incidents',
            'eventType' => 'incident.created',
            'severityFilter' => '',
            'conditionLogic' => 'or',
            'conditionRows' => [
                ['field' => 'severity', 'op' => '>=', 'value' => 'high'],
                ['field' => '', 'op' => '==', 'value' => ''],
                ['field' => 'category', 'op' => 'contains', 'value' => 'phish'],
            ],
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame([
            ['_logic' => 'or'],
            ['field' => 'severity', 'op' => '>=', 'value' => 'high'],
            ['field' => 'category', 'op' => 'contains', 'value' => 'phish'],
        ], $rule->getConditions(), 'empty row dropped, _logic kept first');

        $evaluator = new NotificationRuleEvaluator();
        self::assertTrue($evaluator->evaluate($rule, ['severity' => 'low', 'category' => 'phishing']));
        self::assertFalse($evaluator->evaluate($rule, ['severity' => 'low', 'category' => 'malware']));
    }

    #[Test]
    public function editingKeepsRowsAndSwitchingToAndDropsTheLogicDirective(): void
    {
        $rule = (new NotificationRule())
            ->setName('r')
            ->setEventType('risk.created')
            ->setConditions([['_logic' => 'or'], ['field' => 'score', 'op' => '>', 'value' => 12]]);

        $form = $this->form($rule);
        self::assertSame('or', $form->get('conditionLogic')->getData());
        self::assertCount(1, $form->get('conditionRows'));

        $form->submit([
            'name' => 'r',
            'eventType' => 'risk.created',
            'conditionLogic' => 'and',
            'conditionRows' => [['field' => 'score', 'op' => '>', 'value' => '12']],
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame([['field' => 'score', 'op' => '>', 'value' => '12']], $rule->getConditions());
    }

    #[Test]
    public function storedInRuleWithArrayValueIsEditableAndKeepsTypes(): void
    {
        // Shape written by TemplateInstantiator::setConditions / older rules.
        $rule = (new NotificationRule())
            ->setName('r')
            ->setEventType('incident.created')
            ->setConditions([['field' => 'severity', 'op' => 'in', 'value' => ['high', 'critical']]]);

        $form = $this->form($rule);
        $view = $form->createView();
        self::assertSame('high, critical', $view['conditionRows'][0]['value']->vars['value']);
        self::assertSame('in', $view['conditionRows'][0]['op']->vars['value']);

        $form->submit([
            'name' => 'r',
            'eventType' => 'incident.created',
            'conditionLogic' => 'and',
            'conditionRows' => [['field' => 'severity', 'op' => 'in', 'value' => 'high, critical, medium']],
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame(
            [['field' => 'severity', 'op' => 'in', 'value' => ['high', 'critical', 'medium']]],
            $rule->getConditions(),
        );
        self::assertTrue((new NotificationRuleEvaluator())->evaluate($rule, ['severity' => 'medium']));
    }

    #[Test]
    public function inRuleWithNumericItemsStaysNumeric(): void
    {
        $rule = new NotificationRule();
        $form = $this->form($rule);
        $form->submit([
            'name' => 'r',
            'eventType' => 'risk.created',
            'conditionLogic' => 'and',
            'conditionRows' => [['field' => 'score', 'op' => 'in', 'value' => '12, 15']],
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame([12, 15], $rule->getConditions()[0]['value']);
        self::assertTrue((new NotificationRuleEvaluator())->evaluate($rule, ['score' => 15]));
    }

    /**
     * @param array<mixed> $stored
     * @return array{0: string, 1: NotificationRule}
     */
    private function renderAndResubmit(array $stored, string $op, ?string $editedText = null): array
    {
        $rule = (new NotificationRule())->setName('r')->setEventType('incident.created')
            ->setConditions([['field' => 'f', 'op' => $op, 'value' => $stored]]);
        $form = $this->form($rule);
        $row = $form->createView()['conditionRows'][0];
        $text = $row['value']->vars['value'];
        $form->submit([
            'name' => 'r', 'eventType' => 'incident.created', 'conditionLogic' => 'and',
            // The browser posts the hidden row key with the visible fields.
            'conditionRows' => [[
                'field' => 'f', 'op' => $op, 'value' => $editedText ?? $text,
                'rowKey' => $row['rowKey']->vars['value'],
            ]],
        ]);
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));

        return [$text, $rule];
    }

    #[Test]
    public function itemsContainingCommasAndQuotesRoundTripLosslessly(): void
    {
        $stored = ['a,b', 'c', 'say "hi"', ' padded '];
        [$text, $rule] = $this->renderAndResubmit($stored, 'in');
        self::assertSame('"a,b", c, "say ""hi""", " padded "', $text);
        self::assertSame($stored, $rule->getConditions()[0]['value']);

        // Edited text is parsed CSV-style too (a quoted comma stays one item).
        [, $edited] = $this->renderAndResubmit($stored, 'in', '"a,b", d');
        self::assertSame(['a,b', 'd'], $edited->getConditions()[0]['value']);
    }

    /** @return iterable<string, array{0: array<mixed>}> */
    public static function untouchedValues(): iterable
    {
        yield 'numeric strings' => [['1', '2']];
        yield 'bool' => [[true, false]];
        yield 'null item' => [['x', null]];
        yield 'nested array' => [['x', ['k' => 'v']]];
        yield 'ints and floats' => [[1, 2.5]];
    }

    /** @param array<mixed> $stored */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('untouchedValues')]
    public function unchangedArrayValuesAreRestoredIdenticallyForAnyOperator(array $stored): void
    {
        foreach (['in', '==', 'contains'] as $op) {
            [, $rule] = $this->renderAndResubmit($stored, $op);
            self::assertSame($stored, $rule->getConditions()[0]['value'], $op);
        }
    }

    #[Test]
    public function emptyListIsNotSilentlyRewritten(): void
    {
        $rule = (new NotificationRule())->setName('r')->setEventType('incident.created')
            ->setConditions([['field' => 'f', 'op' => 'in', 'value' => []]]);
        $text = $this->form($rule)->createView()['conditionRows'][0]['value']->vars['value'];
        self::assertSame('', $text);
    }

    #[Test]
    public function editedTextKeepsOriginalItemTypesAndOtherCellsAreStringsUnlessCanonicalNumbers(): void
    {
        // "1","2" are strings in the stored rule: kept as strings when still present.
        [, $rule] = $this->renderAndResubmit(['1', '2'], 'in', '1, 2, 3, 007, x');
        self::assertSame(['1', '2', 3, '007', 'x'], $rule->getConditions()[0]['value']);

        [, $rule] = $this->renderAndResubmit([true], 'in', 'true, 5');
        self::assertSame([true, 5], $rule->getConditions()[0]['value']);
    }

    /**
     * #1059: the original array value is keyed by a stable row key, not by the
     * collection index. Removing the middle row and submitting the remaining
     * rows re-indexed (0, 1) must not hand the last row the removed row's
     * original — its untouched "1, 2" stays the stored strings.
     */
    #[Test]
    public function removingAMiddleRowKeepsEachRowsOriginalAcrossReindexing(): void
    {
        $stored = [
            ['field' => 'score', 'op' => 'in', 'value' => [1, 2]],
            ['field' => 'category', 'op' => 'in', 'value' => ['a']],
            ['field' => 'level', 'op' => 'in', 'value' => ['1', '2']],
        ];

        foreach (['re-indexed' => [0, 1], 'sparse' => [0, 2]] as $label => [$firstIndex, $lastIndex]) {
            $rule = (new NotificationRule())->setName('r')->setEventType('risk.created')->setConditions($stored);
            $form = $this->form($rule);
            $rows = $form->createView()['conditionRows'];
            $posted = static fn (int $i): array => [
                'field' => $rows[$i]['field']->vars['value'],
                'op' => $rows[$i]['op']->vars['value'],
                'value' => $rows[$i]['value']->vars['value'],
                'rowKey' => $rows[$i]['rowKey']->vars['value'],
            ];

            $form->submit([
                'name' => 'r', 'eventType' => 'risk.created', 'conditionLogic' => 'and',
                'conditionRows' => [$firstIndex => $posted(0), $lastIndex => $posted(2)],
            ]);

            self::assertTrue($form->isValid(), (string) $form->getErrors(true));
            self::assertSame(
                [$stored[0], $stored[2]],
                $rule->getConditions(),
                $label . ': each remaining row keeps its own original, the row key is not stored',
            );
        }
    }

    #[Test]
    public function aForeignRowKeyCannotInjectAnOriginal(): void
    {
        $rule = (new NotificationRule())->setName('r')->setEventType('risk.created');
        $form = $this->form($rule);
        $form->submit([
            'name' => 'r', 'eventType' => 'risk.created', 'conditionLogic' => 'and',
            'conditionRows' => [['field' => 'score', 'op' => 'in', 'value' => '1, 2', 'rowKey' => 'forged']],
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame([['field' => 'score', 'op' => 'in', 'value' => [1, 2]]], $rule->getConditions());
    }

    #[Test]
    public function incompleteRowIsRejected(): void
    {
        $form = $this->form(new NotificationRule());
        $form->submit([
            'name' => 'r',
            'eventType' => 'risk.created',
            'conditionLogic' => 'and',
            'conditionRows' => [['field' => 'score', 'op' => '>', 'value' => '']],
        ]);

        self::assertFalse($form->isValid());
    }

    #[Test]
    public function builderRendersChipRowsWithPrototypeAndTranslatedKeywords(): void
    {
        $rule = (new NotificationRule())->setConditions([
            ['field' => 'severity', 'op' => '>=', 'value' => 'high'],
            ['field' => 'status', 'op' => '==', 'value' => 'open'],
        ]);
        $view = $this->form($rule)->createView();

        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        self::getContainer()->get('translator')->setLocale('en');
        $html = $twig->createTemplate(
            "{% import '_components/_fa_condition_builder.html.twig' as b %}"
            . "{{ b.render(form.conditionRows, {logic: form.conditionLogic, suggestions: ['severity']}) }}"
        )->render(['form' => $view]);

        self::assertStringContainsString('data-controller="condition-builder"', $html);
        self::assertStringContainsString('data-condition-builder-index-value="2"', $html);
        self::assertStringContainsString('data-condition-builder-prototype-name-value="__condition_index__"', $html);
        self::assertMatchesRegularExpression('/keyword--when"\s+data-condition-builder-target="keyword">WHEN</', $html);
        self::assertMatchesRegularExpression('/keyword--and"\s+data-condition-builder-target="keyword">AND</', $html);
        self::assertStringContainsString('name="notification_rule[conditionRows][1][op]"', $html);
        self::assertStringContainsString('name="notification_rule[conditionRows][__condition_index__][field]"', $html);
        self::assertStringContainsString('name="notification_rule[conditionRows][1][rowKey]"', $html);
        self::assertMatchesRegularExpression('#<datalist id="fa-condition-fields">\s*<option value="severity"></option>\s*</datalist>#', $html);
        self::assertStringNotContainsString('ui.condition_builder', $html, 'all keys translated');
        self::assertTrue($view['conditionRows']->isRendered());
    }

    private function form(NotificationRule $rule): FormInterface
    {
        self::bootKernel();
        $factory = self::getContainer()->get(FormFactoryInterface::class);
        self::assertInstanceOf(FormFactoryInterface::class, $factory);

        return $factory->create(NotificationRuleType::class, $rule, ['csrf_protection' => false]);
    }
}
