<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Audit-S5 P-12 — User::previousQmsBackground accessor + setter validation.
 */
final class UserPreviousQmsBackgroundTest extends TestCase
{
    #[Test]
    public function defaultsToNull(): void
    {
        $user = new User();
        $this->assertNull($user->getPreviousQmsBackground());
    }

    #[Test]
    public function acceptsAllWhitelistedValues(): void
    {
        $user = new User();
        foreach (['iso_9001', 'iso_14001', 'other', 'none'] as $value) {
            $user->setPreviousQmsBackground($value);
            $this->assertSame($value, $user->getPreviousQmsBackground());
        }
    }

    #[Test]
    public function acceptsNullToReset(): void
    {
        $user = new User();
        $user->setPreviousQmsBackground('iso_9001');
        $user->setPreviousQmsBackground(null);
        $this->assertNull($user->getPreviousQmsBackground());
    }

    #[Test]
    public function rejectsUnknownValue(): void
    {
        $this->expectException(\App\Exception\InvalidArgument\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid previousQmsBackground');
        $user = new User();
        $user->setPreviousQmsBackground('iso_27001');
    }

    #[Test]
    public function setterReturnsSelfForFluentInterface(): void
    {
        $user = new User();
        $this->assertSame($user, $user->setPreviousQmsBackground('iso_9001'));
    }
}
