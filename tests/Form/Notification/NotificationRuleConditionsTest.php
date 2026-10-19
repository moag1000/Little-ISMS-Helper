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
