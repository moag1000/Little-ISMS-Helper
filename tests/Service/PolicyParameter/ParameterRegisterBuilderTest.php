<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyParameter;

use App\Service\PolicyParameter\ParameterRegisterBuilder;
use App\Service\PolicyParameter\PolicyParameterCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParameterRegisterBuilderTest extends TestCase
{
    private function builder(): ParameterRegisterBuilder
    {
        return new ParameterRegisterBuilder(
            new PolicyParameterCatalog(\dirname(__DIR__, 3) . '/config/policy_parameters')
        );
    }

    /** @return array<string, \App\Service\PolicyParameter\RegisterRow> */
    private function rowsByKey(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row->paramKey] = $row;
        }

        return $out;
    }

    #[Test]
    public function it_builds_a_row_per_catalog_param_with_value(): void
    {
        $byKey = $this->rowsByKey($this->builder()->build(['dora'], ['mfa_scope' => 'all']));

        self::assertArrayHasKey('mfa_scope', $byKey);
        self::assertSame('all', $byKey['mfa_scope']->value);
        self::assertContains('A.8.5', $byKey['mfa_scope']->isoClauses);
    }

    #[Test]
    public function dora_constrained_param_is_regulatory_with_source(): void
    {
        $row = $this->rowsByKey($this->builder()->build(['dora'], ['mfa_scope' => 'all']))['mfa_scope'];

        self::assertSame('regulatory', $row->authority);
        self::assertSame('DORA Art. 9(3)', $row->source);
        self::assertContains('dora', $row->frameworks);
        self::assertTrue($row->isRegulatory());
    }

    #[Test]
    public function param_not_constrained_by_selected_frameworks_has_no_authority(): void
    {
        $row = $this->rowsByKey($this->builder()->build(['gdpr'], ['mfa_scope' => 'all']))['mfa_scope'];

        self::assertNull($row->authority);
        self::assertSame([], $row->frameworks);
        self::assertFalse($row->isRegulatory());
    }

    #[Test]
    public function missing_resolved_value_falls_back_to_default(): void
    {
        $row = $this->rowsByKey($this->builder()->build(['dora'], []))['mfa_scope'];

        self::assertSame('privileged_external', $row->value);
    }
}
