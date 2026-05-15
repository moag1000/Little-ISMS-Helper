<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Bsi2004ExerciseLog;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Bsi2004ExerciseLog entity.
 */
final class Bsi2004ExerciseLogTest extends TestCase
{
    #[Test]
    public function defaultsAreCorrect(): void
    {
        $log = new Bsi2004ExerciseLog();

        self::assertSame(Bsi2004ExerciseLog::EXERCISE_TYPE_TABLETOP, $log->getExerciseType());
        self::assertSame(Bsi2004ExerciseLog::TEMPLATE_STANDARD, $log->getBsi2004Template());
        self::assertSame([], $log->getParticipants());
        self::assertSame([], $log->getObjectives());
        self::assertFalse($log->isSubmitted());
        self::assertFalse($log->isConfirmed());
        self::assertNull($log->getOverallRating());
    }

    #[Test]
    public function isSubmittedReturnsTrueAfterSubmittedAtSet(): void
    {
        $log = new Bsi2004ExerciseLog();
        $log->setSubmittedAt(new DateTimeImmutable());

        self::assertTrue($log->isSubmitted());
    }

    #[Test]
    public function isConfirmedReturnsTrueAfterConfirmedAtSet(): void
    {
        $log = new Bsi2004ExerciseLog();
        $log->setConfirmedAt(new DateTimeImmutable());

        self::assertTrue($log->isConfirmed());
    }

    #[Test]
    public function getOverdueImprovementActionsFiltersCorrectly(): void
    {
        $yesterday = (new DateTimeImmutable())->modify('-1 day')->format('Y-m-d');
        $tomorrow  = (new DateTimeImmutable())->modify('+1 day')->format('Y-m-d');

        $log = new Bsi2004ExerciseLog();
        $log->setImprovementActions([
            ['description' => 'Overdue item',    'due_date' => $yesterday, 'completed' => false],
            ['description' => 'Future item',     'due_date' => $tomorrow,  'completed' => false],
            ['description' => 'Completed overdue', 'due_date' => $yesterday, 'completed' => true],
            ['description' => 'No due date',     'completed' => false],
        ]);

        $overdue = $log->getOverdueImprovementActions();
        self::assertCount(1, $overdue);
        self::assertSame('Overdue item', $overdue[0]['description']);
    }

    #[Test]
    public function hasOverdueImprovementActionsReturnsFalseWhenNone(): void
    {
        $log = new Bsi2004ExerciseLog();
        self::assertFalse($log->hasOverdueImprovementActions());
    }

    #[Test]
    public function hasOverdueImprovementActionsReturnsTrueWhenOverdue(): void
    {
        $yesterday = (new DateTimeImmutable())->modify('-1 day')->format('Y-m-d');
        $log = new Bsi2004ExerciseLog();
        $log->setImprovementActions([
            ['description' => 'X', 'due_date' => $yesterday, 'completed' => false],
        ]);

        self::assertTrue($log->hasOverdueImprovementActions());
    }

    #[Test]
    public function settersAndGettersWork(): void
    {
        $log    = new Bsi2004ExerciseLog();
        $tenant = new Tenant();
        $user   = new User();

        $log->setTenant($tenant);
        $log->setExerciseType(Bsi2004ExerciseLog::EXERCISE_TYPE_FULL_SCALE);
        $log->setBsi2004Template(Bsi2004ExerciseLog::TEMPLATE_COMPREHENSIVE);
        $log->setScenarioSummary('Disaster scenario');
        $log->setObjectives(['Objective A', 'Objective B']);
        $log->setParticipants([['name' => 'Alice'], ['name' => 'Bob']]);
        $log->setLessonsLearned('We need more drills.');
        $log->setOverallRating(Bsi2004ExerciseLog::RATING_GOOD);
        $log->setSubmittedBy($user);

        self::assertSame($tenant, $log->getTenant());
        self::assertSame(Bsi2004ExerciseLog::EXERCISE_TYPE_FULL_SCALE, $log->getExerciseType());
        self::assertSame(Bsi2004ExerciseLog::TEMPLATE_COMPREHENSIVE, $log->getBsi2004Template());
        self::assertSame('Disaster scenario', $log->getScenarioSummary());
        self::assertSame(['Objective A', 'Objective B'], $log->getObjectives());
        self::assertSame('We need more drills.', $log->getLessonsLearned());
        self::assertSame(Bsi2004ExerciseLog::RATING_GOOD, $log->getOverallRating());
        self::assertSame($user, $log->getSubmittedBy());
    }

    #[Test]
    public function constantsAreComplete(): void
    {
        self::assertCount(6, Bsi2004ExerciseLog::EXERCISE_TYPES);
        self::assertCount(3, Bsi2004ExerciseLog::TEMPLATES);
        self::assertCount(4, Bsi2004ExerciseLog::RATINGS);
    }
}
