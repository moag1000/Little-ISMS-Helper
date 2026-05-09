<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Dora;

use App\Entity\BCExercise;
use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Repository\BCExerciseRepository;
use App\Repository\TenantPolicySettingRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraTlptCadenceCheck;
use DateTimeImmutable;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DoraTlptCadenceCheckTest extends TestCase
{
    private BCExerciseRepository&MockObject $exerciseRepository;
    private TenantPolicySettingRepository&MockObject $settingRepository;
    private DoraTlptCadenceCheck $check;

    protected function setUp(): void
    {
        $this->exerciseRepository = $this->createMock(BCExerciseRepository::class);
        $this->settingRepository = $this->createMock(TenantPolicySettingRepository::class);
        $this->check = new DoraTlptCadenceCheck(
            $this->exerciseRepository,
            $this->settingRepository,
        );
    }

    #[Test]
    public function testPassesWhenConditionsMet(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // Tenant flagged as DORA-significant.
        $setting = new TenantPolicySetting();
        $setting->setKey(DoraTlptCadenceCheck::SETTING_KEY_SIGNIFICANT);
        $setting->setValue(true);
        $this->settingRepository->method('findOneByTenantAndKey')->willReturn($setting);

        // One TLPT exercise within the trailing 36-month window.
        $exercise = new BCExercise();
        $exercise->setStatus('completed');
        $exercise->setExerciseDate(new DateTimeImmutable('-12 months'));
        $reflection = new \ReflectionClass($exercise);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($exercise, 42);

        $this->exerciseRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$exercise]));

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertTrue($result->details['significant']);
        self::assertNull($result->gap);
    }

    #[Test]
    public function testFailsWhenConditionsMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);

        $setting = new TenantPolicySetting();
        $setting->setKey(DoraTlptCadenceCheck::SETTING_KEY_SIGNIFICANT);
        $setting->setValue(true);
        $this->settingRepository->method('findOneByTenantAndKey')->willReturn($setting);

        // No TLPT exercises matching the heuristic.
        $this->exerciseRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([]));

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertTrue($result->details['significant']);
        self::assertSame(0, $result->details['matching_exercises']);
        self::assertNotNull($result->gap);
    }

    #[Test]
    public function testGapMessageActionable(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // Non-significant tenant: passes with `not_in_tlpt_scope` reason —
        // no gap surfaces because the obligation does not apply.
        $this->settingRepository->method('findOneByTenantAndKey')->willReturn(null);

        $resultNonSignificant = $this->check->run($tenant);
        self::assertTrue($resultNonSignificant->passed);
        self::assertSame('not_in_tlpt_scope', $resultNonSignificant->details['reason']);
        self::assertNull($resultNonSignificant->gap);

        // For a significant tenant with no exercise, the gap surfaces a
        // critical-priority entry routed at the BC-Exercise module.
        $significantCheck = new DoraTlptCadenceCheck(
            $this->exerciseRepository,
            $significantSettingRepo = $this->createMock(TenantPolicySettingRepository::class),
        );
        $sig = new TenantPolicySetting();
        $sig->setKey(DoraTlptCadenceCheck::SETTING_KEY_SIGNIFICANT);
        $sig->setValue(true);
        $significantSettingRepo->method('findOneByTenantAndKey')->willReturn($sig);
        $this->exerciseRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([]));

        $result = $significantCheck->run($tenant);
        self::assertNotNull($result->gap);
        self::assertSame('critical', $result->gap['priority']);
        self::assertSame('app_bc_exercise_index', $result->gap['route']);
        self::assertSame('policy_wizard', $result->gap['translation_domain']);
        self::assertSame(36, DoraTlptCadenceCheck::CADENCE_MONTHS);
    }

    /**
     * @param list<BCExercise> $rows
     */
    private function stubResultQueryBuilder(array $rows): QueryBuilder&MockObject
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn($rows);

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['select', 'where', 'andWhere', 'setParameter', 'orderBy'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        return $qb;
    }
}
