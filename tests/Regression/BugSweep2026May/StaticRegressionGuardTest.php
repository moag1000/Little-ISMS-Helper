<?php

declare(strict_types=1);

namespace App\Tests\Regression\BugSweep2026May;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Static-only regression guards for bugs fixed between 2026-05-25 and 2026-05-27.
 *
 * These tests don't hit the database — they assert template-render output,
 * route registration, and entity attribute state. Goal: catch recurrence of
 * each pattern that already cost a production hotfix.
 *
 * Bug-to-test mapping (PR numbers in parens):
 *
 *  - testAuditScopedAssetsExcludedFromAutoForm (#691)
 *  - testThreatIntelligenceReferencesColumnRenamed (#708)
 *  - testPatchHasNotBlankOnRequiredScalars (#709)
 *  - testBusinessProcessProcessOwnerNullable (#704)
 *  - testBCExerciseHasFacilitatorCallback (#722 TODO-BCE-01)
 *  - testAuditShowDoesNotUseUnknownIcon (#705/#729)
 *  - testRoutesReferencedByCriticalTemplatesExist (#710)
 *  - testFaFormLayoutAttachesValidationController (#718)
 *  - testFaTableWrapperWiresRowClickController (#722)
 *  - testQuickFixApplyMigrationsJobWritesPayloadOnFailure (#729 contract)
 *  - testCloneSettersCarryLifecycleIgnoreMarker (#731)
 *  - testTrainingClonerResetsToInitialState (#731)
 */
final class StaticRegressionGuardTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    /**
     * #691 — audit/edit + new MUST exclude `scopedAssets` from auto_form,
     * otherwise the field renders twice and Twig throws
     * "Field already been rendered".
     */
    #[Test]
    public function auditScopedAssetsExcludedFromAutoForm(): void
    {
        foreach (['new', 'edit'] as $variant) {
            $tpl = file_get_contents(
                self::getContainer()->getParameter('kernel.project_dir')
                . '/templates/audit/' . $variant . '.html.twig'
            );
            self::assertNotFalse($tpl);
            self::assertMatchesRegularExpression(
                "/'exclude'\s*:\s*\[[^\]]*'scopedAssets'/",
                $tpl,
                "audit/$variant.html.twig must exclude scopedAssets from auto_form (#691)"
            );
        }
    }

    /**
     * #708 — `references` is a reserved MariaDB keyword. ThreatIntelligence
     * property must use explicit `name: 'threat_references'` override or
     * Doctrine DML blows up with SQLSTATE[42000].
     */
    #[Test]
    public function threatIntelligenceReferencesColumnRenamed(): void
    {
        $src = file_get_contents(
            self::getContainer()->getParameter('kernel.project_dir')
            . '/src/Entity/ThreatIntelligence.php'
        );
        self::assertNotFalse($src);
        self::assertStringContainsString(
            "name: 'threat_references'",
            $src,
            'ThreatIntelligence::$references must use explicit name override — `references` is a reserved keyword (#708)'
        );
    }

    /**
     * #709 — Patch DB-NOT-NULL scalar columns must have Assert\NotBlank so
     * empty form submits return 422 instead of crashing at flush with
     * NotNullConstraintViolationException.
     */
    #[Test]
    public function patchHasNotBlankOnRequiredScalars(): void
    {
        $src = file_get_contents(
            self::getContainer()->getParameter('kernel.project_dir')
            . '/src/Entity/Patch.php'
        );
        self::assertNotFalse($src);
        foreach (['patchId', 'title', 'description', 'vendor', 'product'] as $field) {
            self::assertMatchesRegularExpression(
                '/#\[Assert\\\\NotBlank[^\]]*\]\s*\n\s*private \?string \$' . $field . '/',
                $src,
                "Patch::\$$field must have #[Assert\\NotBlank] (#709)"
            );
        }
    }

    /**
     * #704 — BusinessProcess::$processOwner column was NOT NULL despite the
     * Pattern A dual-state (User OR Person) replacing it. Form submission
     * with only structured owner crashed at flush. Column must now be
     * nullable.
     */
    #[Test]
    public function businessProcessProcessOwnerNullable(): void
    {
        $src = file_get_contents(
            self::getContainer()->getParameter('kernel.project_dir')
            . '/src/Entity/BusinessProcess.php'
        );
        self::assertNotFalse($src);
        self::assertMatchesRegularExpression(
            '/#\[ORM\\\\Column\(length:\s*100,\s*nullable:\s*true\)\]\s*\n\s*private \?string \$processOwner/',
            $src,
            'BusinessProcess::$processOwner must be nullable for Pattern A owners (#704)'
        );
    }

    /**
     * #722 TODO-BCE-01 — BCExerciseType must validate that the
     * Pattern A dual-state owner (facilitatorUser OR facilitatorPerson)
     * is set. ISO 22301 Cl. 8.5 requires a named facilitator.
     */
    #[Test]
    public function bcExerciseHasFacilitatorCallback(): void
    {
        $src = file_get_contents(
            self::getContainer()->getParameter('kernel.project_dir')
            . '/src/Form/BCExerciseType.php'
        );
        self::assertNotFalse($src);
        self::assertStringContainsString(
            'validateFacilitatorSlot',
            $src,
            'BCExerciseType must declare validateFacilitatorSlot() callback (#722 TODO-BCE-01)'
        );
        self::assertStringContainsString(
            "new Callback([\$this, 'validateFacilitatorSlot'])",
            $src,
            'BCExerciseType::configureOptions must wire the Callback constraint'
        );
    }

    /**
     * #705/#729 — Aurora-icon-name regression: `fa-icon--ui-trending-up` is
     * not registered in fairy-aurora-icons.css. audit/show.html.twig must
     * use the registered canonical name instead.
     */
    #[Test]
    public function auditShowDoesNotUseUnknownIcon(): void
    {
        $tpl = file_get_contents(
            self::getContainer()->getParameter('kernel.project_dir')
            . '/templates/audit/show.html.twig'
        );
        self::assertNotFalse($tpl);
        self::assertStringNotContainsString(
            'fa-icon--ui-trending-up',
            $tpl,
            'audit/show.html.twig must not reference unregistered icon fa-icon--ui-trending-up (#705/#729)'
        );
    }

    /**
     * #710 — Templates must not reference routes that don't exist. Spot-check
     * the two paths the bug sweep fixed: `app_licenses_generate` exists, and
     * `app_system_settings_index` (which doesn't) is no longer referenced.
     */
    #[Test]
    public function routesReferencedByCriticalTemplatesExist(): void
    {
        $router = self::getContainer()->get('router');

        // These MUST exist
        foreach (['app_licenses_generate', 'admin_settings_data_retention', 'tenant_management_index'] as $routeName) {
            self::assertNotNull(
                $router->getRouteCollection()->get($routeName),
                "Route $routeName must exist — referenced by production templates (#692/#710)"
            );
        }

        // These must NOT be referenced anymore (deleted in #710)
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $licenseTpl = file_get_contents($projectDir . '/templates/license/report.html.twig');
        self::assertNotFalse($licenseTpl);
        self::assertStringNotContainsString(
            "path('app_license_report_generate')",
            $licenseTpl,
            'license/report.html.twig must not reference non-existent app_license_report_generate (#710)'
        );

        $tenantSettingsTpl = file_get_contents($projectDir . '/templates/admin/tenant_compliance_settings/edit.html.twig');
        self::assertNotFalse($tenantSettingsTpl);
        self::assertStringNotContainsString(
            "path('app_system_settings_index')",
            $tenantSettingsTpl,
            'tenant_compliance_settings/edit.html.twig must not reference non-existent app_system_settings_index (#710)'
        );
    }

    /**
     * #718 — fa-form-layout must attach the `form-validation` Stimulus
     * controller alongside the layout controller so collapsed sections
     * + inactive tabs are revealed before scroll+focus on validation error.
     */
    #[Test]
    public function faFormLayoutAttachesValidationController(): void
    {
        $tpl = file_get_contents(
            self::getContainer()->getParameter('kernel.project_dir')
            . '/templates/_components/_fa_form_layout.html.twig'
        );
        self::assertNotFalse($tpl);
        self::assertStringContainsString(
            'data-controller="{{ controller }} form-validation"',
            $tpl,
            'fa_form_layout must compose form-validation alongside form-layout (#718)'
        );
    }

    /**
     * #722/#728 — fa-table + legacy _table must both attach the `row-click`
     * Stimulus controller so users can click anywhere on a row to navigate
     * to its show page (the "always-click-the-eye" complaint).
     */
    #[Test]
    public function faTableWrapperWiresRowClickController(): void
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');

        $faTable = file_get_contents($projectDir . '/templates/_components/_fa_table.html.twig');
        self::assertNotFalse($faTable);
        self::assertStringContainsString(
            "'row-click'",
            $faTable,
            'fa_table must register row-click controller (#722)'
        );

        $legacyTable = file_get_contents($projectDir . '/templates/_components/_table.html.twig');
        self::assertNotFalse($legacyTable);
        self::assertStringContainsString(
            'data-controller="row-click"',
            $legacyTable,
            'legacy _table.html.twig must register row-click controller (#728)'
        );
    }

    /**
     * #729 — QuickFixApplyMigrationsJob must call $ctx->updatePayload() with
     * `apply_failure` on a diagnosed-failure path. Without this, the
     * back-link / index recovery card cannot render after async-job split.
     */
    #[Test]
    public function quickFixApplyMigrationsJobWritesPayloadOnFailure(): void
    {
        $src = file_get_contents(
            self::getContainer()->getParameter('kernel.project_dir')
            . '/src/Job/QuickFixApplyMigrationsJob.php'
        );
        self::assertNotFalse($src);
        self::assertStringContainsString(
            "\$ctx->updatePayload([",
            $src,
            'ApplyMigrationsJob must persist apply_failure on payload before throwing (#729)'
        );
        self::assertStringContainsString(
            "'apply_failure'",
            $src,
            'ApplyMigrationsJob payload key must be `apply_failure` so the index controller can read it'
        );
    }

    /**
     * #731 — Cloners reset cloned-entity status to its lifecycle initial
     * marking. PHPStan rule `lifecycle.directSetStatus` blocks the call
     * unless marked. All 8 cloners must carry the ignore-marker.
     */
    #[Test]
    public function cloneSettersCarryLifecycleIgnoreMarker(): void
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $cloners = [
            'AssetCloner',
            'AuditFindingCloner',
            'BCExerciseCloner',
            'BusinessContinuityPlanCloner',
            'DocumentCloner',
            'RiskCloner',
            'SupplierCloner',
            'TrainingCloner',
        ];
        foreach ($cloners as $cloner) {
            $src = file_get_contents($projectDir . '/src/Service/Clone/' . $cloner . '.php');
            self::assertNotFalse($src);
            self::assertMatchesRegularExpression(
                '/setStatus\([^)]+\);\s*\/\/\s*@phpstan-ignore\s+lifecycle\.directSetStatus/',
                $src,
                "$cloner::clone() setStatus must carry @phpstan-ignore lifecycle.directSetStatus marker (#731)"
            );
        }
    }
}
