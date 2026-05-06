<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\ProcessingActivity\ThirdCountryWithoutSafeguardsRule;
use App\Entity\ProcessingActivity;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ThirdCountryWithoutSafeguardsRuleTest extends TestCase
{
    private ThirdCountryWithoutSafeguardsRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new ThirdCountryWithoutSafeguardsRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesWhenTransferActiveAndSafeguardsMissing(): void
    {
        $pa = $this->buildPa(thirdCountry: true, safeguards: '', status: 'active');

        $this->assertTrue($this->rule->appliesTo($pa, $this->user));
    }

    #[Test]
    public function doesNotApplyOnDraft(): void
    {
        $pa = $this->buildPa(thirdCountry: true, safeguards: '', status: 'draft');

        $this->assertFalse($this->rule->appliesTo($pa, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenSafeguardsRecorded(): void
    {
        $pa = $this->buildPa(thirdCountry: true, safeguards: 'EU SCC 2021/914', status: 'active');

        $this->assertFalse($this->rule->appliesTo($pa, $this->user));
    }

    #[Test]
    public function doesNotApplyWithoutThirdCountryTransfer(): void
    {
        $pa = $this->buildPa(thirdCountry: false, safeguards: '', status: 'active');

        $this->assertFalse($this->rule->appliesTo($pa, $this->user));
    }

    private function buildPa(bool $thirdCountry, string $safeguards, string $status): ProcessingActivity
    {
        $pa = new ProcessingActivity();
        $pa->setHasThirdCountryTransfer($thirdCountry);
        $pa->setTransferSafeguards($safeguards === '' ? null : $safeguards);
        $pa->setStatus($status);

        return $pa;
    }
}
