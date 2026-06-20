<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UserMappingOnboardingStateTest extends TestCase
{
    #[Test]
    public function defaults_to_empty_array(): void
    {
        self::assertSame([], (new User())->getMappingOnboardingState());
    }

    #[Test]
    public function round_trips_state(): void
    {
        $u = new User();
        $u->setMappingOnboardingState(['step' => 1, 'completed' => ['laden']]);
        self::assertSame(['step' => 1, 'completed' => ['laden']], $u->getMappingOnboardingState());
    }
}
