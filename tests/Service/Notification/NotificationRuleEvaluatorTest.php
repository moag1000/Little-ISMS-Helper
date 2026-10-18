<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Entity\Notification\NotificationRule;
use App\Service\Notification\NotificationRuleEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationRuleEvaluatorTest extends TestCase
{
    private NotificationRuleEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new NotificationRuleEvaluator();
    }

    private function ruleWith(array $conditions): NotificationRule
    {
        $rule = new NotificationRule();
        $rule->setConditions($conditions);
        return $rule;
    }

    // --- Operator coverage ---

    #[Test]
    public function testEqualsOperatorMatches(): void
    {
        $rule = $this->ruleWith([['field' => 'status', 'op' => '==', 'value' => 'open']]);
        self::assertTrue($this->evaluator->evaluate($rule, ['status' => 'open']));
    }

    #[Test]
    public function testEqualsOperatorDoesNotMatch(): void
    {
        $rule = $this->ruleWith([['field' => 'status', 'op' => '==', 'value' => 'open']]);
        self::assertFalse($this->evaluator->evaluate($rule, ['status' => 'closed']));
    }

    #[Test]
    public function testNotEqualsOperator(): void
    {
        $rule = $this->ruleWith([['field' => 'status', 'op' => '!=', 'value' => 'closed']]);
        self::assertTrue($this->evaluator->evaluate($rule, ['status' => 'open']));
        self::assertFalse($this->evaluator->evaluate($rule, ['status' => 'closed']));
    }

    #[Test]
    public function testGreaterThanNumeric(): void
    {
        $rule = $this->ruleWith([['field' => 'score', 'op' => '>', 'value' => '10']]);
        self::assertTrue($this->evaluator->evaluate($rule, ['score' => 15]));
        self::assertFalse($this->evaluator->evaluate($rule, ['score' => 10]));
    }

    #[Test]
    public function testLessThanNumeric(): void
    {
        $rule = $this->ruleWith([['field' => 'score', 'op' => '<', 'value' => '10']]);
        self::assertTrue($this->evaluator->evaluate($rule, ['score' => 5]));
        self::assertFalse($this->evaluator->evaluate($rule, ['score' => 10]));
    }

    #[Test]
    public function testGreaterThanOrEqualSeverity(): void
    {
        $rule = $this->ruleWith([['field' => 'severity', 'op' => '>=', 'value' => 'high']]);
        self::assertTrue($this->evaluator->evaluate($rule, ['severity' => 'high']));
        self::assertTrue($this->evaluator->evaluate($rule, ['severity' => 'critical']));
        self::assertFalse($this->evaluator->evaluate($rule, ['severity' => 'medium']));
        self::assertFalse($this->evaluator->evaluate($rule, ['severity' => 'low']));
    }

    #[Test]
    public function testLessThanOrEqualSeverity(): void
    {
        $rule = $this->ruleWith([['field' => 'severity', 'op' => '<=', 'value' => 'medium']]);
        self::assertTrue($this->evaluator->evaluate($rule, ['severity' => 'low']));
        self::assertTrue($this->evaluator->evaluate($rule, ['severity' => 'medium']));
        self::assertFalse($this->evaluator->evaluate($rule, ['severity' => 'high']));
    }

    #[Test]
    public function testInOperator(): void
    {
        $rule = $this->ruleWith([['field' => 'type', 'op' => 'in', 'value' => ['incident', 'breach']]]);
        self::assertTrue($this->evaluator->evaluate($rule, ['type' => 'incident']));
        self::assertFalse($this->evaluator->evaluate($rule, ['type' => 'risk']));
    }

    #[Test]
    public function testContainsOperator(): void
    {
        $rule = $this->ruleWith([['field' => 'description', 'op' => 'contains', 'value' => 'PII']]);
        self::assertTrue($this->evaluator->evaluate($rule, ['description' => 'Includes PII data']));
        self::assertFalse($this->evaluator->evaluate($rule, ['description' => 'No personal data']));
    }

    // --- Logic combinations ---

    #[Test]
    public function testAndLogicAllMustMatch(): void
    {
        $rule = $this->ruleWith([
            ['field' => 'severity', 'op' => '>=', 'value' => 'high'],
            ['field' => 'status', 'op' => '==', 'value' => 'open'],
        ]);

        self::assertTrue($this->evaluator->evaluate($rule, ['severity' => 'high', 'status' => 'open']));
        self::assertFalse($this->evaluator->evaluate($rule, ['severity' => 'high', 'status' => 'closed']));
        self::assertFalse($this->evaluator->evaluate($rule, ['severity' => 'low', 'status' => 'open']));
    }

    #[Test]
    public function testOrLogicAnyMustMatch(): void
    {
        $rule = $this->ruleWith([
            ['_logic' => 'or'],
            ['field' => 'severity', 'op' => '>=', 'value' => 'high'],
            ['field' => 'status', 'op' => '==', 'value' => 'critical_override'],
        ]);

        self::assertTrue($this->evaluator->evaluate($rule, ['severity' => 'high', 'status' => 'open']));
        self::assertTrue($this->evaluator->evaluate($rule, ['severity' => 'low', 'status' => 'critical_override']));
        self::assertFalse($this->evaluator->evaluate($rule, ['severity' => 'low', 'status' => 'open']));
    }

    // --- Edge cases ---

    #[Test]
    public function testEmptyConditionsAlwaysMatches(): void
    {
        $rule = $this->ruleWith([]);
        self::assertTrue($this->evaluator->evaluate($rule, []));
        self::assertTrue($this->evaluator->evaluate($rule, ['anything' => 'here']));
    }

    #[Test]
    public function testMissingFieldReturnsFalse(): void
    {
        $rule = $this->ruleWith([['field' => 'severity', 'op' => '>=', 'value' => 'high']]);
        self::assertFalse($this->evaluator->evaluate($rule, ['other_field' => 'value']));
    }

    #[Test]
    public function testMalformedConditionReturnsFalse(): void
    {
        $rule = $this->ruleWith([['no_field_key' => 'bad']]);
        self::assertFalse($this->evaluator->evaluate($rule, ['severity' => 'high']));
    }

    /** The rule form stores text, so a boolean state must match "true" / "1" / "false". */
    #[Test]
    #[DataProvider('booleanValues')]
    public function booleanStateMatchesTextValue(bool $state, mixed $expected, bool $matches): void
    {
        $rule = $this->ruleWith([['field' => 'evidenceOutdated', 'op' => '==', 'value' => $expected]]);
        self::assertSame($matches, $this->evaluator->evaluate($rule, ['evidenceOutdated' => $state]));
    }

    /** @return iterable<string, array{bool, mixed, bool}> */
    public static function booleanValues(): iterable
    {
        yield 'true vs "true"' => [true, 'true', true];
        yield 'true vs "1"' => [true, '1', true];
        yield 'true vs bool true' => [true, true, true];
        yield 'false vs "false"' => [false, 'false', true];
        yield 'false vs "0"' => [false, '0', true];
        yield 'false vs "true"' => [false, 'true', false];
        yield 'true vs "no"' => [true, 'no', false];
    }

    #[Test]
    public function ruleAccessorsSplitAndMergeTheLogicDirective(): void
    {
        $rule = $this->ruleWith([['field' => 'a', 'op' => '==', 'value' => '1']]);
        self::assertSame('and', $rule->getConditionLogic());

        $rule->setConditionLogic('or');
        self::assertSame([['_logic' => 'or'], ['field' => 'a', 'op' => '==', 'value' => '1']], $rule->getConditions());

        $rule->setConditionRows([['field' => 'b', 'op' => '!=', 'value' => '2']]);
        self::assertSame([['_logic' => 'or'], ['field' => 'b', 'op' => '!=', 'value' => '2']], $rule->getConditions());
        self::assertSame([['field' => 'b', 'op' => '!=', 'value' => '2']], $rule->getConditionRows());

        $rule->setConditionLogic('and');
        self::assertSame([['field' => 'b', 'op' => '!=', 'value' => '2']], $rule->getConditions());
    }
}
