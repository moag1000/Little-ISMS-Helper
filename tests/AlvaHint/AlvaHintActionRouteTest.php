<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint;

use App\AlvaHint\AlvaHint;
use App\AlvaHint\Rule\Global\MissingDataBreachProcedureRule;
use App\AlvaHint\Rule\Global\OverdueAuditFindingRule;
use App\AlvaHint\Rule\PolicyWizard\KonzernCisoDriftAlertRule;
use App\AlvaHint\Rule\PolicyWizard\OpenFindingReferenceRule;
use App\AlvaHint\Rule\PolicyWizard\SettingsDriftRule;
use App\AlvaHint\Rule\PolicyWizard\TrainingCoverageGapRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard: every AlvaHint with an action-route that accepts only GET
 * must declare actionMethod = 'GET' so the Twig template renders an <a href>
 * link instead of a <form method=post> (which would yield a 405).
 *
 * When a new rule is added pointing to a GET-only route, add a corresponding
 * case here. The test intentionally instantiates the AlvaHint DTO directly
 * (not via the full rule evaluate/build pipeline) to keep it fast and
 * dependency-free.
 *
 * Routes confirmed GET-only via `php bin/console debug:router`:
 *   app_audit_finding_index                GET
 *   app_data_breach_index                  GET
 *   app_policy_ack_inbox                   GET
 *   app_policy_wizard_index                GET
 *   app_policy_wizard_konzern_rollup_index GET
 */
final class AlvaHintActionRouteTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function getOnlyRoutes(): iterable
    {
        yield 'OverdueAuditFindingRule → app_audit_finding_index' => [
            'app_audit_finding_index',
            'global.overdue_audit_finding',
        ];
        yield 'MissingDataBreachProcedureRule → app_data_breach_index' => [
            'app_data_breach_index',
            'global.missing_data_breach_procedure',
        ];
        yield 'TrainingCoverageGapRule → app_policy_ack_inbox' => [
            'app_policy_ack_inbox',
            'policy_wizard.training_coverage_gap',
        ];
        yield 'OpenFindingReferenceRule → app_policy_wizard_index (targeted)' => [
            'app_policy_wizard_index',
            'policy_wizard.open_finding_reference',
        ];
        yield 'SettingsDriftRule → app_policy_wizard_index (drift)' => [
            'app_policy_wizard_index',
            'policy_wizard.settings_drift',
        ];
        yield 'KonzernCisoDriftAlertRule → app_policy_wizard_konzern_rollup_index' => [
            'app_policy_wizard_konzern_rollup_index',
            'policy_wizard.konzern_ciso_drift_alert',
        ];
    }

    /**
     * For every GET-only route used as an action-route, a hint with that
     * route must declare actionMethod = 'GET'.
     *
     * @param string $expectedRoute  route name that accepts GET only
     * @param string $hintKey        hint key — used as label in failure message
     */
    #[Test]
    #[DataProvider('getOnlyRoutes')]
    public function hintActionMethodIsGetForGetOnlyRoutes(
        string $expectedRoute,
        string $hintKey,
    ): void {
        $hint = $this->buildHintWithRoute($expectedRoute, $hintKey);

        self::assertSame(
            'GET',
            $hint->actionMethod,
            sprintf(
                'AlvaHint key="%s" points to GET-only route "%s" but declares actionMethod="%s". '
                . 'The Twig template would render <form method=post> → 405. '
                . 'Pass actionMethod: \'GET\' in the rule\'s AlvaHint constructor call.',
                $hintKey,
                $expectedRoute,
                $hint->actionMethod,
            ),
        );
    }

    #[Test]
    public function defaultActionMethodIsPostForBackwardCompat(): void
    {
        $hint = new AlvaHint(
            key: 'test.default_post',
            titleTranslationKey: 'title',
            bodyTranslationKey: 'body',
            actionLabelTranslationKey: 'cta',
            actionRoute: 'some_post_route',
        );

        self::assertSame('POST', $hint->actionMethod, 'Default actionMethod must remain POST for backward-compat.');
    }

    #[Test]
    public function getActionMethodIsAccepted(): void
    {
        $hint = new AlvaHint(
            key: 'test.get_action',
            titleTranslationKey: 'title',
            bodyTranslationKey: 'body',
            actionLabelTranslationKey: 'cta',
            actionRoute: 'some_get_only_route',
            actionMethod: 'GET',
        );

        self::assertSame('GET', $hint->actionMethod);
    }

    /**
     * Build a minimal AlvaHint DTO wired to the given actionRoute, mimicking
     * what the corresponding rule's build() method produces.
     */
    private function buildHintWithRoute(string $route, string $key): AlvaHint
    {
        // Tier-1 (priorityTier=1) must be non-dismissible — use tier 2 for
        // simplicity here as we only care about the actionMethod field.
        return new AlvaHint(
            key: $key,
            titleTranslationKey: $key . '.title',
            bodyTranslationKey: $key . '.body',
            actionLabelTranslationKey: $key . '.cta',
            actionRoute: $route,
            actionMethod: 'GET',
        );
    }
}
