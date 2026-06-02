<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Entity\OrganizationSecurityProfile;
use App\Repository\OrganizationSecurityProfileRepository;
use App\Repository\TenantPolicySettingRepository;
use App\Repository\UserRepository;
use App\Service\PolicyParameter\FrameworkConstraintChecker;
use App\Service\PolicyParameter\FrameworkCoverageEvaluator;
use App\Service\PolicyParameter\PolicyBaselineApplier;
use App\Service\PolicyParameter\PolicyBaselineCatalog;
use App\Service\PolicyParameter\PolicyParameterCatalog;
use App\Service\PolicyParameter\PolicyParameterResolver;
use App\Service\PolicyParameter\PolicyParameterVariables;
use App\Service\PolicyParameter\PolicyProfileManager;
use App\Service\PolicyWizard\VariableCollector;
use App\Service\PolicyWizard\WizardStepKeys;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W3 — VariableCollector unit tests.
 *
 * Asserts the §11.2 "do not make the user re-type" promise: tenant
 * data + WizardRun.inputs flow into a flat var bag without leaking
 * raw template markers.
 */
#[AllowMockObjectsWithoutExpectations]
final class VariableCollectorTest extends TestCase
{
    private function makeTenant(int $id = 7, ?string $legalName = 'MyCompany GmbH', ?string $name = 'MyCompany'): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getLegalName')->willReturn($legalName);
        $stub->method('getName')->willReturn($name);
        return $stub;
    }

    private function makeUser(int $id, string $first, string $last, string $email = 'user@example.com'): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getFullName')->willReturn(trim($first . ' ' . $last));
        $user->method('getEmail')->willReturn($email);
        return $user;
    }

    private function makeRun(Tenant $tenant, array $inputs): WizardRun
    {
        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setInputs($inputs);
        return $run;
    }

    private function makeCollector(
        ?TenantPolicySettingRepository $settingRepo = null,
        ?UserRepository $userRepo = null,
    ): VariableCollector {
        $settingRepo ??= $this->createStub(TenantPolicySettingRepository::class);
        $userRepo ??= $this->createStub(UserRepository::class);
        return new VariableCollector($settingRepo, $userRepo);
    }

    #[Test]
    public function mergesPolicyParameterInterpolationVariables(): void
    {
        $configRoot = \dirname(__DIR__, 3) . '/config';
        $params = new PolicyParameterCatalog($configRoot . '/policy_parameters');
        $baselines = new PolicyBaselineCatalog($configRoot . '/policy_baselines');
        $manager = new PolicyProfileManager(
            $params,
            $baselines,
            new PolicyParameterResolver($params),
            new PolicyBaselineApplier($baselines),
            new FrameworkCoverageEvaluator($params, new FrameworkConstraintChecker()),
        );

        // Profile pre-filled from the finance_bafin baseline -> mfa_scope = all.
        $profile = new OrganizationSecurityProfile();
        $manager->applySector($profile, 'finance_bafin');

        $profileRepo = $this->createStub(OrganizationSecurityProfileRepository::class);
        $profileRepo->method('findForTenant')->willReturn($profile);

        $collector = new VariableCollector(
            $this->createStub(TenantPolicySettingRepository::class),
            $this->createStub(UserRepository::class),
            $profileRepo,
            $manager,
            new PolicyParameterVariables($params),
        );

        $vars = $collector->collectFor($this->makeRun($this->makeTenant(), []));

        // mfa_scope -> template_slot.interpolate policy.access.mfa_value
        self::assertSame('all', $vars['policy.access.mfa_value']);
        self::assertSame('dual_signoff', $vars['policy.governance.approval']);
    }

    #[Test]
    public function collectsTenantLegalNameFromInputThenFromTenant(): void
    {
        $tenant = $this->makeTenant(legalName: 'TenantFallbackName GmbH');
        $run = $this->makeRun($tenant, [
            WizardStepKeys::STEP_ORG_SCOPE => [
                'legal_name' => 'WizardOverride GmbH',
                'scope_statement' => 'All HQ operations and the Berlin DC.',
            ],
        ]);
        $collector = $this->makeCollector();

        $vars = $collector->collectFor($run);

        self::assertSame('WizardOverride GmbH', $vars['tenant.legal_name']);
        self::assertSame('All HQ operations and the Berlin DC.', $vars['tenant.scope_statement']);
        self::assertSame(7, $vars['tenant.id']);
    }

    #[Test]
    public function fallsBackToTenantPolicySettingForScopeWhenNoInput(): void
    {
        $tenant = $this->makeTenant();

        $setting = new TenantPolicySetting();
        $setting->setKey('isms.scope_statement');
        $setting->setValue('Persisted scope.');

        $settingRepo = $this->createMock(TenantPolicySettingRepository::class);
        $settingRepo->method('findOneByTenantAndKey')
            ->willReturnCallback(
                static fn (Tenant $t, string $key): ?TenantPolicySetting => $key === 'isms.scope_statement'
                    ? $setting
                    : null,
            );

        $run = $this->makeRun($tenant, [
            WizardStepKeys::STEP_ORG_SCOPE => [
                'legal_name' => 'Foo AG',
                // no scope_statement
            ],
        ]);
        $collector = $this->makeCollector($settingRepo);

        $vars = $collector->collectFor($run);

        self::assertSame('Persisted scope.', $vars['tenant.scope_statement']);
    }

    #[Test]
    public function resolvesRoleUserNamesViaUserRepository(): void
    {
        $tenant = $this->makeTenant();
        $ciso = $this->makeUser(101, 'Anna', 'Lieber', 'anna@example.com');
        $dpo = $this->makeUser(202, 'Bernd', 'Klee', 'bernd@example.com');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('find')->willReturnMap([
            [101, $ciso],
            [202, $dpo],
        ]);

        $run = $this->makeRun($tenant, [
            WizardStepKeys::STEP_ROLES => [
                'roles' => [
                    'ciso' => 101,
                    'dpo' => 202,
                ],
            ],
        ]);
        $collector = $this->makeCollector(userRepo: $userRepo);

        $vars = $collector->collectFor($run);

        self::assertSame('Anna Lieber', $vars['roles.ciso.fullName']);
        self::assertSame('Bernd Klee', $vars['roles.dpo.fullName']);
    }

    #[Test]
    public function pullsRiskAndOperationalBaselineSlots(): void
    {
        $tenant = $this->makeTenant();
        $run = $this->makeRun($tenant, [
            WizardStepKeys::STEP_RISK_CLASSIFICATION => [
                'risk_appetite_tier' => 2,
                'data_classification_levels' => 4,
            ],
            WizardStepKeys::STEP_OPERATIONAL_BASELINES => [
                'crypto_allowlist' => ['AES-256', 'RSA-2048'],
                'backup_rpo_hours' => 4,
            ],
            WizardStepKeys::STEP_LIFECYCLE => [
                'review_interval_months' => 12,
            ],
        ]);
        $collector = $this->makeCollector();

        $vars = $collector->collectFor($run);

        self::assertSame(2, $vars['risk.appetite_tier']);
        self::assertSame(4, $vars['risk.classification_levels']);
        self::assertSame('AES-256, RSA-2048', $vars['crypto.algorithms']);
        self::assertSame(4, $vars['backup.rpo_hours']);
        self::assertSame(12, $vars['lifecycle.review_interval_months']);
    }

    #[Test]
    public function collectsAllTenOperationalBaselinesAsSubstitutionVars(): void
    {
        $tenant = $this->makeTenant();
        $run = $this->makeRun($tenant, [
            WizardStepKeys::STEP_OPERATIONAL_BASELINES => [
                // pre-existing 4
                'crypto_allowlist' => ['AES-256', 'RSA-2048'],
                'backup_rpo_hours' => 4,
                'patch_sla_hours' => ['critical' => 4, 'high' => 24, 'medium' => 168],
                'continuity_rto_hours' => ['mission_critical' => 2, 'important' => 8],
                // 6 added in #829
                'access_review_cadence_months' => 6,
                'mfa_scope' => 'privileged_only',
                'logging_retention_months' => ['security' => 12, 'app' => 3, 'system' => 3],
                'vuln_scan_cadence' => ['external_cadence' => 'monthly', 'internal_cadence' => 'weekly'],
                'working_modes' => ['office', 'hybrid'],
                'cloud_onprem_mix_pct' => 50,
            ],
        ]);
        $collector = $this->makeCollector();

        $vars = $collector->collectFor($run);

        // pre-existing 4 — now ALL exposed (patch + continuity were the gap)
        self::assertSame('AES-256, RSA-2048', $vars['crypto.algorithms']);
        self::assertSame(4, $vars['backup.rpo_hours']);
        self::assertSame(4, $vars['patch.sla_critical_hours']);
        self::assertSame(24, $vars['patch.sla_high_hours']);
        self::assertSame(168, $vars['patch.sla_medium_hours']);
        self::assertSame('mission_critical: 2h, important: 8h', $vars['continuity.rto_summary']);

        // 6 added in #829
        self::assertSame(6, $vars['access.review_cadence_months']);
        self::assertSame('privileged_only', $vars['mfa.scope']);
        self::assertSame(12, $vars['logging.retention_security_months']);
        self::assertSame(3, $vars['logging.retention_app_months']);
        self::assertSame(3, $vars['logging.retention_system_months']);
        self::assertSame('monthly', $vars['vuln.scan_external_cadence']);
        self::assertSame('weekly', $vars['vuln.scan_internal_cadence']);
        self::assertSame('office, hybrid', $vars['working.modes']);
        self::assertSame(50, $vars['cloud.onprem_mix_pct']);
    }

    #[Test]
    public function dropsKeysWithNoSourceSoNoLeftoverMarkers(): void
    {
        $tenant = $this->makeTenant(legalName: null, name: null);
        $run = $this->makeRun($tenant, []);
        $collector = $this->makeCollector();

        $vars = $collector->collectFor($run);

        // tenant.legal_name has no source whatsoever → omitted entirely.
        self::assertArrayNotHasKey('tenant.legal_name', $vars);
        // role-fullName slots should also be absent.
        self::assertArrayNotHasKey('roles.ciso.fullName', $vars);
        self::assertArrayNotHasKey('roles.dpo.fullName', $vars);
    }
}
