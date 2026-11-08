<?php

declare(strict_types=1);

namespace App\Tests\Service\Risk;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Enum\TreatmentStrategy;
use App\Repository\RiskRepository;
use App\Service\RiskApprovalConfigResolver;
use App\Service\RiskApprovalConfigView;
use App\Service\Risk\RiskAcceptanceRequest;
use App\Service\Risk\RiskAcceptanceRequestSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** A requested risk acceptance is a duty of the approval level's role — so it reaches mail and MCP my_day. */
final class RiskAcceptanceRequestSourceTest extends TestCase
{
    #[Test]
    public function onlyRequestedAndUndecidedAcceptancesAreOpen(): void
    {
        $requested = $this->risk(residual: 5, justification: 'Kosten > Nutzen');
        $notRequested = $this->risk(residual: 5, justification: null);
        $repository = $this->createStub(RiskRepository::class);
        $repository->method('findBy')->willReturn([$requested, $notRequested]);

        $open = [...$this->source($repository)->findOpen(new Tenant())];

        self::assertCount(1, $open);
        self::assertSame($requested, $open[0]->risk);
    }

    #[Test]
    public function managerLevelGoesToTheRiskPersonasExecutiveLevelToTopManagement(): void
    {
        $source = $this->source($this->createStub(RiskRepository::class));

        // Defaults: up to 7 manager, above executive.
        $manager = $source->responsibleOf(new RiskAcceptanceRequest($this->risk(residual: 5, justification: 'x')));
        self::assertSame(RiskAcceptanceRequestSource::MANAGER_LEVEL_ROLES, $manager->roles);
        // Organisations without risk specialists: every manager (the voter's own role), not the admins.
        self::assertSame(['ROLE_MANAGER'], $manager->fallbackRoles);
        self::assertTrue($manager->adminFallback);

        $executive = $source->responsibleOf(new RiskAcceptanceRequest($this->risk(residual: 20, justification: 'x')));
        self::assertSame(['ROLE_TOP_MGMT'], $executive->roles);
        self::assertFalse($executive->adminFallback);
    }

    #[Test]
    public function aFormalAcceptanceOrAnotherStrategyCompletesTheDuty(): void
    {
        $source = $this->source($this->createStub(RiskRepository::class));
        $accepted = $this->risk(residual: 5, justification: 'x', accepted: true);
        $mitigated = $this->risk(residual: 5, justification: 'x', strategy: TreatmentStrategy::Mitigate);

        self::assertTrue($source->isCompleted(new RiskAcceptanceRequest($accepted)));
        self::assertTrue($source->isCompleted(new RiskAcceptanceRequest($mitigated)));
        self::assertFalse($source->isCompleted(new RiskAcceptanceRequest($this->risk(residual: 5, justification: 'x'))));
        self::assertSame('app_risk_show', $source->linkOf(new RiskAcceptanceRequest($accepted))->route);
    }

    private function source(RiskRepository $repository): RiskAcceptanceRequestSource
    {
        $config = $this->createStub(RiskApprovalConfigResolver::class);
        $config->method('resolveFor')->willReturn(RiskApprovalConfigView::defaults());

        return new RiskAcceptanceRequestSource($repository, $config);
    }

    private function risk(
        int $residual,
        ?string $justification,
        bool $accepted = false,
        TreatmentStrategy $strategy = TreatmentStrategy::Accept,
    ): Risk {
        $risk = $this->createStub(Risk::class);
        $risk->method('getTenant')->willReturn(new Tenant());
        $risk->method('getResidualRiskLevel')->willReturn($residual);
        $risk->method('getAcceptanceJustification')->willReturn($justification);
        $risk->method('isFormallyAccepted')->willReturn($accepted);
        $risk->method('getTreatmentStrategy')->willReturn($strategy);
        $risk->method('getId')->willReturn(7);

        return $risk;
    }
}
