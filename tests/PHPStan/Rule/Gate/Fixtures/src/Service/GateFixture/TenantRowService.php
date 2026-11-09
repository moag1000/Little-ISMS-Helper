<?php

declare(strict_types=1);

namespace App\Service\GateFixture;

use App\Entity\Asset;
use App\Entity\Asset as A;
use App\Entity\Risk;
use App\Entity\Tag;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/** Gate 72 fixture (new-no-tenant): every `expect:` line is a mutation the rule must report. */
final class TenantRowService
{
    private ?Asset $current = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function withTenant(Tenant $tenant): Asset
    {
        $asset = new Asset();
        $asset->setTenant($tenant);

        return $asset;
    }

    public function withoutTenant(): Asset
    {
        $asset = new Asset(); // expect: gate72.newNoTenant

        return $asset;
    }

    public function tenantNull(): void
    {
        $asset = new Asset(); // expect: gate72.newNoTenant
        $asset->setTenant(null);
        $this->em->persist($asset);
    }

    public function otherObjectGetsTheTenant(Tenant $tenant): void
    {
        $risk = new Risk();
        $risk->setTenant($tenant);
        $this->em->persist(new Asset()); // expect: gate72.newNoTenant
    }

    public function secondObjectForgotten(Tenant $tenant): void
    {
        $a = new Risk();
        $b = new Asset(); // expect: gate72.newNoTenant
        $a->setTenant($tenant);
        $this->em->persist($b);
    }

    public function alias(): A
    {
        return new A(); // expect: gate72.newNoTenant
    }

    public function fullyQualified(): object
    {
        return new \App\Entity\Asset(); // expect: gate72.newNoTenant
    }

    public function fluent(Tenant $tenant): Asset
    {
        return (new Asset())->setTenant($tenant);
    }

    public function fluentOnVariable(Tenant $tenant): Asset
    {
        $asset = new Asset();
        $asset->setName('x')
            ->setTenant($tenant);

        return $asset;
    }

    public function property(Tenant $tenant): void
    {
        $this->current = new Asset();
        $this->current->setTenant($tenant);
    }

    public function sharedRowsMayBeGlobal(): Tag
    {
        return new Tag();
    }

    public function commentDoesNotCount(): Asset
    {
        $asset = new Asset(); // expect: gate72.newNoTenant
        // $asset->setTenant($tenant);

        return $asset;
    }
}
