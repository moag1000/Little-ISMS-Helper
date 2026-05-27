<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\DoraSubcontractor;
use App\Entity\Supplier;
use App\Entity\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see DoraSubcontractor} (RT_04 entity, Bucket-6 close).
 *
 * Covers: defaults, validation-friendly setters (LEI/country uppercase),
 * self-reference cycle guard, parent/child relationship lifecycle.
 */
final class DoraSubcontractorTest extends TestCase
{
    #[Test]
    public function defaultsMatchSpec(): void
    {
        $sub = new DoraSubcontractor();
        self::assertNull($sub->getId());
        self::assertSame(2, $sub->getTier(), 'Default tier = 2 (direct subcontractor of prime)');
        self::assertSame('standard', $sub->getCriticality());
        self::assertSame('medium', $sub->getSubstitutability());
        self::assertNull($sub->getLeiCode());
        self::assertNull($sub->getCountry());
        self::assertNull($sub->getParentSubcontractor());
        self::assertCount(0, $sub->getChildren());
    }

    #[Test]
    public function leiCodeIsUppercased(): void
    {
        $sub = new DoraSubcontractor();
        $sub->setLeiCode('529900t8bm49aursdo55');
        self::assertSame('529900T8BM49AURSDO55', $sub->getLeiCode());
    }

    #[Test]
    public function leiCodeEmptyStringBecomesNull(): void
    {
        $sub = new DoraSubcontractor();
        $sub->setLeiCode('');
        self::assertNull($sub->getLeiCode());
    }

    #[Test]
    public function countryIsUppercased(): void
    {
        $sub = new DoraSubcontractor();
        $sub->setCountry('de');
        self::assertSame('DE', $sub->getCountry());
    }

    #[Test]
    public function selfReferenceIsRejected(): void
    {
        $sub = new DoraSubcontractor();
        $sub->setParentSubcontractor($sub);
        self::assertNull(
            $sub->getParentSubcontractor(),
            'Pointing parentSubcontractor at self must be silently nullified to avoid cycle.'
        );
    }

    #[Test]
    public function addChildEstablishesBackReference(): void
    {
        $parent = new DoraSubcontractor();
        $child = new DoraSubcontractor();
        $parent->addChild($child);

        self::assertCount(1, $parent->getChildren());
        self::assertSame($parent, $child->getParentSubcontractor());
    }

    #[Test]
    public function removeChildClearsBackReference(): void
    {
        $parent = new DoraSubcontractor();
        $child = new DoraSubcontractor();
        $parent->addChild($child);
        $parent->removeChild($child);

        self::assertCount(0, $parent->getChildren());
        self::assertNull($child->getParentSubcontractor());
    }

    #[Test]
    public function rootSupplierResolvesToParentSupplier(): void
    {
        $supplier = new Supplier();
        $supplier->setName('Prime ICT');
        $sub = new DoraSubcontractor();
        $sub->setParentSupplier($supplier);

        self::assertSame($supplier, $sub->getRootSupplier());
    }

    #[Test]
    public function tenantAssignmentRoundtrips(): void
    {
        $tenant = new Tenant();
        $sub = new DoraSubcontractor();
        $sub->setTenant($tenant);
        self::assertSame($tenant, $sub->getTenant());
    }

    #[Test]
    public function tierAndCriticalityRoundtrip(): void
    {
        $sub = new DoraSubcontractor();
        $sub->setTier(4);
        $sub->setCriticality('critical');
        $sub->setSubstitutability('low');

        self::assertSame(4, $sub->getTier());
        self::assertSame('critical', $sub->getCriticality());
        self::assertSame('low', $sub->getSubstitutability());
    }

    #[Test]
    public function constantsExposeChoiceSets(): void
    {
        self::assertContains('critical', DoraSubcontractor::CRITICALITY_CHOICES);
        self::assertContains('important', DoraSubcontractor::CRITICALITY_CHOICES);
        self::assertContains('standard', DoraSubcontractor::CRITICALITY_CHOICES);
        self::assertContains('high', DoraSubcontractor::SUBSTITUTABILITY_CHOICES);
        self::assertContains('medium', DoraSubcontractor::SUBSTITUTABILITY_CHOICES);
        self::assertContains('low', DoraSubcontractor::SUBSTITUTABILITY_CHOICES);
    }
}
