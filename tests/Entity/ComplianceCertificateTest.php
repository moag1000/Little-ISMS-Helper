<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ComplianceCertificate;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComplianceCertificateTest extends TestCase
{
    #[Test]
    public function isExpiredReflectsValidUntil(): void
    {
        $c = new ComplianceCertificate();
        $c->setValidUntil(new DateTimeImmutable('2020-01-01'));
        self::assertTrue($c->isExpired(new DateTimeImmutable('2026-01-01')));
        $c->setValidUntil(new DateTimeImmutable('2999-01-01'));
        self::assertFalse($c->isExpired(new DateTimeImmutable('2026-01-01')));
    }

    #[Test]
    public function scopeTagsDefaultEmpty(): void
    {
        self::assertSame([], (new ComplianceCertificate())->getScopeTags());
    }
}
