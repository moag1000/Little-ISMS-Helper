<?php

declare(strict_types=1);

namespace App\Tests\Risk;

use App\Entity\RiskApprovalConfig;
use App\Entity\Tenant;
use App\Repository\RiskApprovalConfigRepository;
use App\Risk\RiskScaleProvider;
use App\Service\RiskApprovalConfigResolver;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RiskScaleProviderTest extends TestCase
{
    #[Test]
    public function theOrganisationsEdgesAndLevelMeaningsComeFromItsCriteria(): void
    {
        $tenant = $this->tenant(1);
        $config = (new RiskApprovalConfig())->setTenant($tenant)
            ->setBandCriticalMin(16)->setBandHighMin(10)->setBandMediumMin(5)
            ->setImpactCriteria(['5' => ' Schaden über 1 Mio. € ', '2' => '', '9' => 'ignoriert']);

        $provider = $this->provider($tenant, [1 => $config]);

        self::assertSame(['critical' => 16, 'high' => 10, 'medium' => 5], $provider->currentEdges());
        $criteria = $provider->criteria();
        self::assertSame('Schaden über 1 Mio. €', $criteria['impact'][5]);
        self::assertSame('', $criteria['impact'][2]);
        self::assertArrayNotHasKey(9, $criteria['impact']);
        self::assertSame(['', '', '', '', ''], array_values($criteria['probability']));
    }

    #[Test]
    public function aSubsidiaryIsNeverLaxerThanItsHolding(): void
    {
        $holding = $this->tenant(1);
        $subsidiary = $this->tenant(2, $holding);
        $holdingConfig = (new RiskApprovalConfig())->setTenant($holding)
            ->setBandCriticalMin(15)->setBandHighMin(12)->setBandMediumMin(6)
            ->setProbabilityCriteria(['1' => 'seltener als alle zehn Jahre']);
        $ownConfig = (new RiskApprovalConfig())->setTenant($subsidiary)
            ->setBandCriticalMin(20)->setBandHighMin(9)->setBandMediumMin(6);

        $provider = $this->provider($subsidiary, [1 => $holdingConfig, 2 => $ownConfig]);

        self::assertSame(['critical' => 15, 'high' => 9, 'medium' => 6], $provider->currentEdges());
        self::assertSame('seltener als alle zehn Jahre', $provider->criteria()['probability'][1]);
    }

    #[Test]
    public function noOrganisationInScopeMeansNoOwnScale(): void
    {
        $context = $this->createStub(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn(null);
        $provider = new RiskScaleProvider($context, new RiskApprovalConfigResolver($this->createStub(RiskApprovalConfigRepository::class)));

        self::assertNull($provider->currentEdges());
    }

    #[Test]
    public function bandsOutOfOrderAreRejected(): void
    {
        $config = (new RiskApprovalConfig())->setBandCriticalMin(12)->setBandHighMin(12)->setBandMediumMin(6);
        $validator = \Symfony\Component\Validator\Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $paths = array_map(
            static fn ($v): string => $v->getPropertyPath(),
            iterator_to_array($validator->validate($config)),
        );
        self::assertContains('bandHighMin', $paths);
    }

    /** @param array<int, RiskApprovalConfig> $configs */
    private function provider(Tenant $current, array $configs): RiskScaleProvider
    {
        $context = $this->createStub(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn($current);
        $repository = $this->createStub(RiskApprovalConfigRepository::class);
        $repository->method('findByTenant')->willReturnCallback(
            static fn (Tenant $t): ?RiskApprovalConfig => $configs[$t->getId()] ?? null,
        );

        return new RiskScaleProvider($context, new RiskApprovalConfigResolver($repository));
    }

    private function tenant(int $id, ?Tenant $parent = null): Tenant
    {
        $tenant = new Tenant();
        $tenant->setCode('t' . $id)->setName('T' . $id);
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);
        if ($parent !== null) {
            $tenant->setParent($parent);
        }

        return $tenant;
    }
}
