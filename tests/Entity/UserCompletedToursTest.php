<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit-Tests für User::completedTours-Helpers (Sprint 13).
 */
class UserCompletedToursTest extends TestCase
{
    #[Test]
    public function testDefaultsToEmptyArray(): void
    {
        $user = new User();
        $this->assertSame([], $user->getCompletedTours());
        $this->assertFalse($user->hasCompletedTour('junior'));
    }

    #[Test]
    public function testMarkTourAppendsOnce(): void
    {
        $user = new User();
        $user->markTourCompleted('junior');
        $user->markTourCompleted('junior');
        $this->assertSame(['junior'], $user->getCompletedTours());
        $this->assertTrue($user->hasCompletedTour('junior'));
    }

    #[Test]
    public function testMarkMultipleToursPreservesOrder(): void
    {
        $user = new User();
        $user->markTourCompleted('junior');
        $user->markTourCompleted('cm');
        $user->markTourCompleted('ciso');
        $this->assertSame(['junior', 'cm', 'ciso'], $user->getCompletedTours());
    }

    #[Test]
    public function testResetTourRemovesOnlyThatTour(): void
    {
        $user = new User();
        $user->markTourCompleted('junior');
        $user->markTourCompleted('cm');
        $user->resetTour('junior');
        $this->assertSame(['cm'], $user->getCompletedTours());
        $this->assertFalse($user->hasCompletedTour('junior'));
        $this->assertTrue($user->hasCompletedTour('cm'));
    }

    #[Test]
    public function testResetTourIsIdempotent(): void
    {
        $user = new User();
        $user->markTourCompleted('cm');
        $user->resetTour('junior');
        $user->resetTour('junior');
        $this->assertSame(['cm'], $user->getCompletedTours());
    }

    #[Test]
    public function testResetAllClears(): void
    {
        $user = new User();
        $user->markTourCompleted('junior');
        $user->markTourCompleted('cm');
        $user->resetAllTours();
        $this->assertSame([], $user->getCompletedTours());
    }

    #[Test]
    public function testFluentInterface(): void
    {
        $user = new User();
        $result = $user->markTourCompleted('junior')->markTourCompleted('cm')->resetTour('cm');
        $this->assertSame($user, $result);
        $this->assertSame(['junior'], $user->getCompletedTours());
    }
}
