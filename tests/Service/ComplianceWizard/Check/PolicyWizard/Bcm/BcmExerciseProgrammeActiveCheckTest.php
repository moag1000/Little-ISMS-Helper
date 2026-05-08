<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Bcm;

use App\Entity\BCExercise;
use App\Entity\Tenant;
use App\Repository\BCExerciseRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmExerciseProgrammeActiveCheck;
use DateTimeImmutable;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class BcmExerciseProgrammeActiveCheckTest extends TestCase
{
    private BCExerciseRepository&MockObject $exerciseRepository;
    private BcmExerciseProgrammeActiveCheck $check;

    protected function setUp(): void
    {
        $this->exerciseRepository = $this->createMock(BCExerciseRepository::class);
        $this->check = new BcmExerciseProgrammeActiveCheck($this->exerciseRepository);
    }

    #[Test]
    public function testPassesWhenCompletedExerciseInLast12Months(): void
    {
        $tenant = $this->createMock(Tenant::class);

        $exercise = new BCExercise();
        $exercise->setStatus('completed');
        $exercise->setExerciseDate(new DateTimeImmutable('-3 months'));

        $this->exerciseRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$exercise]));

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(1, $result->details['completed_in_last_12m']);
        self::assertNull($result->gap);
        self::assertSame('bcm', $this->check->getStandard());
    }

    #[Test]
    public function testFailsWhenNoExerciseInWindow(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->exerciseRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([]));

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertSame(0, $result->details['completed_in_last_12m']);
        self::assertSame(0, $result->details['planned_next_12m']);
        self::assertNotNull($result->gap);
        self::assertSame('critical', $result->gap['priority']);
    }

    #[Test]
    public function testGapMessageActionableAndPlannedExercisePasses(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // Planned future exercise → passes.
        $planned = new BCExercise();
        $planned->setStatus('planned');
        $planned->setExerciseDate(new DateTimeImmutable('+2 months'));
        $this->exerciseRepository->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([$planned]));

        $result = $this->check->run($tenant);
        self::assertTrue($result->passed);
        self::assertSame(1, $result->details['planned_next_12m']);

        // Empty repo: fail with actionable gap routed to BCExercise module.
        $emptyRepo = $this->createMock(BCExerciseRepository::class);
        $emptyRepo->method('createQueryBuilder')
            ->willReturn($this->stubResultQueryBuilder([]));
        $emptyCheck = new BcmExerciseProgrammeActiveCheck($emptyRepo);
        $emptyResult = $emptyCheck->run($tenant);

        self::assertNotNull($emptyResult->gap);
        self::assertSame('app_bc_exercise_index', $emptyResult->gap['route']);
        self::assertSame('policy_wizard', $emptyResult->gap['translation_domain']);
        self::assertSame(
            'compliance_check.bcm_exercise_programme_active.fail_message',
            $emptyResult->gap['title'],
        );

        $nullResult = $this->check->run(null);
        self::assertFalse($nullResult->passed);
        self::assertSame('no_tenant', $nullResult->details['reason']);
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
        foreach (['where', 'andWhere', 'setParameter', 'orderBy'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        return $qb;
    }
}
