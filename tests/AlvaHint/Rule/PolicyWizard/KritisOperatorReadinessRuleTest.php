<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\PolicyWizard;

use App\AlvaHint\Rule\PolicyWizard\KritisOperatorReadinessRule;
use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\TenantPolicySettingRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W5-E3 — KritisOperatorReadinessRule unit tests.
 *
 * Pattern mirrors SettingsDriftRuleTest: pure unit test, repositories
 * mocked, role enforcement is out-of-scope (handled by AlvaHintService
 * via Security::isGranted, covered there). The rule emits the data
 * needed for the dismissal token (key + version) and the
 * non-dismissible flag — telemetry counters live in AlvaHintService.
 */
#[AllowMockObjectsWithoutExpectations]
final class KritisOperatorReadinessRuleTest extends TestCase
{
    private TenantPolicySettingRepository&MockObject $settingRepository;
    private DocumentRepository&MockObject $documentRepository;
    private User $user;

    protected function setUp(): void
    {
        $this->settingRepository = $this->createMock(TenantPolicySettingRepository::class);
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->user = new User();
    }

    #[Test]
    public function testFiresWhenKritisActiveAndNoBsiPolicy(): void
    {
        $tenant = $this->makeTenant(11, 'Klinik Nord');

        $this->settingRepository->method('findOneByTenantAndKey')
            ->willReturn($this->kritisFlag(true));

        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(0));

        $rule = new KritisOperatorReadinessRule(
            $this->settingRepository,
            $this->documentRepository,
        );

        self::assertTrue($rule->appliesTo($tenant, $this->user));
    }

    #[Test]
    public function testSkipsWhenKritisActiveAndBsiPolicyExists(): void
    {
        $tenant = $this->makeTenant(11, 'Klinik Nord');

        $this->settingRepository->method('findOneByTenantAndKey')
            ->willReturn($this->kritisFlag(true));

        // One approved BSI it_security_policy document already exists.
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(1));

        $rule = new KritisOperatorReadinessRule(
            $this->settingRepository,
            $this->documentRepository,
        );

        self::assertFalse($rule->appliesTo($tenant, $this->user));
    }

    #[Test]
    public function testSkipsWhenKritisInactive(): void
    {
        $tenant = $this->makeTenant(11, 'Acme GmbH');

        // Setting absent — non-KRITIS tenant.
        $this->settingRepository->method('findOneByTenantAndKey')->willReturn(null);

        // Even with no BSI policy at all, non-KRITIS tenants must NOT
        // see the hint — guards against false-positive noise.
        $this->documentRepository->expects(self::never())->method('createQueryBuilder');

        $rule = new KritisOperatorReadinessRule(
            $this->settingRepository,
            $this->documentRepository,
        );

        self::assertFalse($rule->appliesTo($tenant, $this->user));

        // Setting present but explicitly disabled — same outcome.
        $disabledRepo = $this->createMock(TenantPolicySettingRepository::class);
        $disabledRepo->method('findOneByTenantAndKey')->willReturn($this->kritisFlag(false));
        $disabledDocs = $this->createMock(DocumentRepository::class);
        $disabledDocs->expects(self::never())->method('createQueryBuilder');

        $disabledRule = new KritisOperatorReadinessRule($disabledRepo, $disabledDocs);
        self::assertFalse($disabledRule->appliesTo($tenant, $this->user));
    }

    #[Test]
    public function testSkipsWhenWrongRole(): void
    {
        // Role enforcement is performed by AlvaHintService through
        // Security::isGranted on the AlvaHint::$requiredRoles array. The
        // rule only declares the requirement — verify the declaration so
        // a regression in build() can't silently drop the role gate that
        // protects KRITIS hints from being shown to plain users.
        $tenant = $this->makeTenant(11, 'Klinik Nord');

        $this->settingRepository->method('findOneByTenantAndKey')
            ->willReturn($this->kritisFlag(true));
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(0));

        $rule = new KritisOperatorReadinessRule(
            $this->settingRepository,
            $this->documentRepository,
        );
        $hint = $rule->build($tenant, $this->user);

        self::assertSame(['ROLE_ADMIN', 'ROLE_GROUP_CISO'], $hint->requiredRoles);
        self::assertNotContains('ROLE_USER', $hint->requiredRoles);
        self::assertNotContains('ROLE_AUDITOR', $hint->requiredRoles);
    }

    #[Test]
    public function testRenderAndDismissTelemetryEvents(): void
    {
        // The rule itself does not record telemetry — AlvaHintService
        // does, keyed on AlvaHint::$key + ::$version + ::$dismissible.
        // Verify the rule emits the stable values telemetry depends on.
        $tenant = $this->makeTenant(42, 'Stadtwerke Süd');

        $this->settingRepository->method('findOneByTenantAndKey')
            ->willReturn($this->kritisFlag(true));
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(0));

        $rule = new KritisOperatorReadinessRule(
            $this->settingRepository,
            $this->documentRepository,
        );

        $hint = $rule->build($tenant, $this->user);

        self::assertSame('policy_wizard.kritis_operator_readiness', $hint->key);
        self::assertSame(KritisOperatorReadinessRule::VERSION, $hint->version);
        self::assertSame(1, $hint->priorityTier);
        self::assertFalse($hint->dismissible, 'Tier-1 hints must not be dismissible');
        self::assertSame('Tenant', $hint->entityType);
        self::assertSame(42, $hint->entityId);
        self::assertSame('alva', $hint->translationDomain);
        self::assertSame('alva_hint.kritis_operator_readiness.title', $hint->titleTranslationKey);
        self::assertSame('alva_hint.kritis_operator_readiness.body', $hint->bodyTranslationKey);
        self::assertSame('alva_hint.kritis_operator_readiness.cta_label', $hint->actionLabelTranslationKey);
        self::assertSame('app_compliance_wizard_start', $hint->actionRoute);
        self::assertSame(['wizard' => 'bsi_grundschutz'], $hint->actionRouteParams);
        self::assertSame('Stadtwerke Süd', $hint->bodyTranslationParams['%tenant_name%'] ?? null);
        self::assertSame(['policy_wizard', 'bsi_grundschutz'], $rule->requiredModules());
    }

    private function kritisFlag(bool $value): TenantPolicySetting
    {
        $setting = new TenantPolicySetting();
        $setting->setKey(KritisOperatorReadinessRule::SETTING_KEY_KRITIS);
        $setting->setValue($value);
        return $setting;
    }

    private function makeTenant(int $id, string $name): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getName')->willReturn($name);
        return $stub;
    }

    private function stubScalarQueryBuilder(int $count): QueryBuilder&MockObject
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSingleScalarResult'])
            ->getMock();
        $query->method('getSingleScalarResult')->willReturn($count);

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['select', 'innerJoin', 'where', 'andWhere', 'setParameter'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        return $qb;
    }
}
