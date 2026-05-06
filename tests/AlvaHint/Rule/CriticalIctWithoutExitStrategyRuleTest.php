<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Supplier\CriticalIctWithoutExitStrategyRule;
use App\Entity\Document;
use App\Entity\Supplier;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CriticalIctWithoutExitStrategyRuleTest extends TestCase
{
    private CriticalIctWithoutExitStrategyRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new CriticalIctWithoutExitStrategyRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesForCriticalIctWithoutExitStrategy(): void
    {
        $supplier = $this->buildSupplier('critical', false, null);
        $this->assertTrue($this->rule->appliesTo($supplier, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenHasExitStrategyFlag(): void
    {
        $supplier = $this->buildSupplier('critical', true, null);
        $this->assertFalse($this->rule->appliesTo($supplier, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenExitDocumentLinked(): void
    {
        $supplier = $this->buildSupplier('critical', false, new Document());
        $this->assertFalse($this->rule->appliesTo($supplier, $this->user));
    }

    #[Test]
    public function doesNotApplyForNonCritical(): void
    {
        $supplier = $this->buildSupplier('important', false, null);
        $this->assertFalse($this->rule->appliesTo($supplier, $this->user));
    }

    private function buildSupplier(string $ict, bool $hasExit, ?Document $doc): Supplier
    {
        $supplier = new Supplier();
        $supplier->setIctCriticality($ict);
        $supplier->setHasExitStrategy($hasExit);
        $supplier->setExitStrategyDocument($doc);
        return $supplier;
    }
}
