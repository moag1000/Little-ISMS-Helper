<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyParameter;

use App\Service\PolicyParameter\FrameworkConstraintChecker;
use App\Service\PolicyParameter\FrameworkCoverageEvaluator;
use App\Service\PolicyParameter\PolicyParameterCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrameworkCoverageEvaluatorTest extends TestCase
{
    private function evaluator(): FrameworkCoverageEvaluator
    {
        $catalog = new PolicyParameterCatalog(\dirname(__DIR__, 3) . '/config/policy_parameters');

        return new FrameworkCoverageEvaluator($catalog, new FrameworkConstraintChecker());
    }

    #[Test]
    public function dora_with_weak_values_reports_gaps(): void
    {
        $resolved = ['mfa_scope' => 'privileged_external', 'approval_model' => 'single_ciso'];

        $coverage = $this->evaluator()->evaluate(['dora'], $resolved);

        $dora = $coverage['dora'];
        self::assertSame('dora', $dora->framework);
        self::assertGreaterThanOrEqual(2, $dora->totalConstrained);
        self::assertGreaterThanOrEqual(2, count($dora->violations));
        self::assertSame(0, $dora->satisfiedCount());
    }

    #[Test]
    public function dora_with_strong_values_is_fully_covered(): void
    {
        $resolved = ['mfa_scope' => 'all', 'approval_model' => 'dual_signoff'];

        $dora = $this->evaluator()->evaluate(['dora'], $resolved)['dora'];

        self::assertCount(0, $dora->violations);
        self::assertSame($dora->totalConstrained, $dora->satisfiedCount());
    }
}
