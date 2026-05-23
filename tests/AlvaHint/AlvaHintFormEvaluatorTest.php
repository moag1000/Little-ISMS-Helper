<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;
use App\Service\AlvaHint\AlvaHintFormEvaluator;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class AlvaHintFormEvaluatorTest extends TestCase
{
    #[Test]
    public function returnsEmptyWhenUserIsAnonymous(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);
        $modules = $this->createMock(ModuleConfigurationService::class);

        $evaluator = new AlvaHintFormEvaluator($security, $modules, [$this->ruleAlwaysFires('incident', 'foo')]);

        self::assertSame([], $evaluator->evaluate('incident', []));
    }

    #[Test]
    public function skipsRulesForOtherEntityTypes(): void
    {
        $evaluator = $this->buildEvaluator([
            $this->ruleAlwaysFires('risk', 'probability'),
        ]);

        self::assertSame([], $evaluator->evaluate('incident', []));
    }

    #[Test]
    public function returnsHintWhenRuleFires(): void
    {
        $rule = $this->ruleAlwaysFires('incident', 'dataBreachOccurred');
        $evaluator = $this->buildEvaluator([$rule]);

        $hints = $evaluator->evaluate('incident', ['dataBreachOccurred' => true]);

        self::assertCount(1, $hints);
        self::assertSame('dataBreachOccurred', $hints[0]->field);
    }

    #[Test]
    public function gatesByRequiredModules(): void
    {
        $rule = $this->createMock(AlvaHintFormRuleInterface::class);
        $rule->method('entityType')->willReturn('incident');
        $rule->method('requiredModules')->willReturn(['privacy']);
        $rule->method('requiredRoles')->willReturn([]);
        // supports() must NOT be called when the module gate excludes the rule.
        $rule->expects(self::never())->method('supports');

        // Tenant has incidents but not privacy.
        $evaluator = $this->buildEvaluator([$rule], ['incidents']);

        self::assertSame([], $evaluator->evaluate('incident', []));
    }

    #[Test]
    public function gatesByRequiredRoles(): void
    {
        $rule = $this->createMock(AlvaHintFormRuleInterface::class);
        $rule->method('entityType')->willReturn('incident');
        $rule->method('requiredModules')->willReturn([]);
        $rule->method('requiredRoles')->willReturn(['ROLE_CISO']);
        $rule->expects(self::never())->method('supports');

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(new User());
        $security->method('isGranted')->willReturnMap([
            ['ROLE_CISO', null, false],
        ]);

        $modules = $this->createMock(ModuleConfigurationService::class);
        $modules->method('getActiveModules')->willReturn(['incidents', 'privacy']);

        $evaluator = new AlvaHintFormEvaluator($security, $modules, [$rule]);

        self::assertSame([], $evaluator->evaluate('incident', []));
    }

    #[Test]
    public function dedupesHintsByField(): void
    {
        $ruleA = $this->ruleAlwaysFires('incident', 'dataBreachOccurred', 'alpha');
        $ruleB = $this->ruleAlwaysFires('incident', 'dataBreachOccurred', 'beta');
        $evaluator = $this->buildEvaluator([$ruleA, $ruleB]);

        $hints = $evaluator->evaluate('incident', []);

        self::assertCount(1, $hints, 'first-match-per-field wins');
        self::assertSame('alpha', $hints[0]->key);
    }

    /**
     * @param list<AlvaHintFormRuleInterface> $rules
     * @param list<string>                    $activeModules
     */
    private function buildEvaluator(array $rules, array $activeModules = ['incidents', 'privacy', 'risks']): AlvaHintFormEvaluator
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(new User());
        $security->method('isGranted')->willReturn(true);

        $modules = $this->createMock(ModuleConfigurationService::class);
        $modules->method('getActiveModules')->willReturn($activeModules);

        return new AlvaHintFormEvaluator($security, $modules, $rules);
    }

    private function ruleAlwaysFires(string $entityType, string $field, string $key = 'k.test'): AlvaHintFormRuleInterface
    {
        $rule = $this->createMock(AlvaHintFormRuleInterface::class);
        $rule->method('entityType')->willReturn($entityType);
        $rule->method('requiredModules')->willReturn([]);
        $rule->method('requiredRoles')->willReturn([]);
        $rule->method('supports')->willReturn(true);
        $rule->method('evaluate')->willReturn(new AlvaFormHint(
            key: $key,
            field: $field,
            tier: 'info',
            titleTranslationKey: 't',
            bodyTranslationKey: 'b',
        ));
        return $rule;
    }
}
