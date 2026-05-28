<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\TisaxLicenseConfirmation;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TisaxLicenseConfirmation::isValid() TTL logic.
 *
 * A confirmation is valid when confirmedAt is within the last 24 hours.
 */
final class TisaxLicenseConfirmationTest extends TestCase
{
    #[Test]
    public function testIsValidReturnsFalseAfter24Hours(): void
    {
        $confirmation = new TisaxLicenseConfirmation();
        // 25 hours ago — beyond the 24 h window
        $confirmation->setConfirmedAt(new DateTimeImmutable('-25 hours'));

        self::assertFalse($confirmation->isValid(), 'A confirmation older than 24 h must be invalid');
    }

    #[Test]
    public function testIsValidReturnsTrueWithin24Hours(): void
    {
        $confirmation = new TisaxLicenseConfirmation();
        // 23 hours ago — still within the 24 h window
        $confirmation->setConfirmedAt(new DateTimeImmutable('-23 hours'));

        self::assertTrue($confirmation->isValid(), 'A confirmation younger than 24 h must be valid');
    }

    #[Test]
    public function testIsValidReturnsFalseWhenConfirmedAtIsNull(): void
    {
        $confirmation = new TisaxLicenseConfirmation();
        $confirmation->setConfirmedAt(null);

        self::assertFalse($confirmation->isValid(), 'A confirmation without a timestamp must be invalid');
    }

    #[Test]
    public function testDefaultConstructorSetsConfirmedAtToNow(): void
    {
        $before       = new DateTimeImmutable('-1 second');
        $confirmation = new TisaxLicenseConfirmation();
        $after        = new DateTimeImmutable('+1 second');

        $confirmedAt = $confirmation->getConfirmedAt();
        self::assertNotNull($confirmedAt);
        self::assertGreaterThanOrEqual($before, $confirmedAt);
        self::assertLessThanOrEqual($after, $confirmedAt);
    }
}
