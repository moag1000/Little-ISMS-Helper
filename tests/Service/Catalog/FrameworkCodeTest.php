<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Service\Catalog\FrameworkCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrameworkCodeTest extends TestCase
{
    #[Test]
    public function canonicalCodesAreUnique(): void
    {
        $this->assertSame(
            count(FrameworkCode::CANONICAL),
            count(array_unique(FrameworkCode::CANONICAL)),
            'Duplicate canonical framework code',
        );
    }

    #[Test]
    public function noTwoCanonicalCodesNormaliseToTheSameKey(): void
    {
        $byNorm = [];
        foreach (FrameworkCode::CANONICAL as $code) {
            $norm = strtoupper((string) preg_replace('/[-_.\s]/', '', $code));
            $byNorm[$norm][] = $code;
        }
        foreach ($byNorm as $norm => $codes) {
            $this->assertCount(
                1,
                $codes,
                sprintf('Canonical codes collide on normalised key %s: %s', $norm, implode(', ', $codes)),
            );
        }
    }

    #[Test]
    public function everyAliasResolvesToACanonicalCode(): void
    {
        foreach (FrameworkCode::ALIASES as $alias => $target) {
            $this->assertTrue(
                FrameworkCode::isCanonical($target),
                sprintf('Alias %s points at non-canonical target %s', $alias, $target),
            );
        }
    }

    #[Test]
    public function noAliasIsAlsoCanonical(): void
    {
        foreach (array_keys(FrameworkCode::ALIASES) as $alias) {
            $this->assertFalse(
                FrameworkCode::isCanonical($alias),
                sprintf('%s is listed as both alias and canonical', $alias),
            );
        }
    }

    #[Test]
    public function canonicalizeResolvesCanonicalAndAliasAndUnknown(): void
    {
        $this->assertSame('ISO-22301', FrameworkCode::canonicalize('ISO-22301'));
        $this->assertSame('ISO-22301', FrameworkCode::canonicalize('ISO22301'));
        $this->assertSame('NIST-CSF', FrameworkCode::canonicalize('NIST-CSF-2.0'));
        $this->assertSame('NIS2UMSUCG', FrameworkCode::canonicalize('NIS2-UmsuCG'));
        $this->assertNull(FrameworkCode::canonicalize('BAIT'), 'Deferred framework must not resolve');
    }
}
