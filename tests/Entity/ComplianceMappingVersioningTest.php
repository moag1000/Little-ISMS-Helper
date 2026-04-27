<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ComplianceMapping;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ComplianceMappingVersioningTest extends TestCase
{
    #[Test]
    public function testValidAtReturnsTrueForCurrentMapping(): void
    {
        $mapping = new ComplianceMapping();
        $mapping->setValidFrom(new DateTimeImmutable('2025-01-01'));

        $this->assertTrue($mapping->isValidAt(new DateTimeImmutable('2026-01-01')));
    }

    #[Test]
    public function testValidAtReturnsFalseBeforeValidFrom(): void
    {
        $mapping = new ComplianceMapping();
        $mapping->setValidFrom(new DateTimeImmutable('2025-06-01'));

        $this->assertFalse($mapping->isValidAt(new DateTimeImmutable('2025-01-01')));
    }

    #[Test]
    public function testValidAtRespectsValidUntil(): void
    {
        $mapping = new ComplianceMapping();
        $mapping->setValidFrom(new DateTimeImmutable('2025-01-01'));
        $mapping->setValidUntil(new DateTimeImmutable('2025-06-01'));

        $this->assertTrue($mapping->isValidAt(new DateTimeImmutable('2025-03-01')));
        $this->assertFalse($mapping->isValidAt(new DateTimeImmutable('2025-07-01')));
    }

    #[Test]
    public function testDefaultSourceValue(): void
    {
        $mapping = new ComplianceMapping();
        $this->assertSame('algorithm_generated_v1.0', $mapping->getSource());
    }
}
