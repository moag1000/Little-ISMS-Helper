<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\DoraDataFlow;
use App\Entity\Supplier;
use App\Entity\Tenant;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see DoraDataFlow} — DORA RoI Art. 28 RT_03 entity.
 */
final class DoraDataFlowTest extends TestCase
{
    #[Test]
    public function defaultsAreCorrectOnConstruction(): void
    {
        $flow = new DoraDataFlow();

        self::assertNull($flow->getId());
        self::assertNull($flow->getTenant());
        self::assertNull($flow->getSupplier());
        self::assertSame([], $flow->getDataCategories());
        self::assertSame(DoraDataFlow::DIRECTION_OUTBOUND, $flow->getDirection());
        self::assertNull($flow->getProcessingPurpose());
        self::assertSame([], $flow->getSecurityMeasures());
        self::assertNull($flow->getDataVolume());
        self::assertFalse($flow->isCrossBorder());
        self::assertNull($flow->getReceivingCountry());
        self::assertInstanceOf(DateTimeImmutable::class, $flow->getCreatedAt());
        self::assertNull($flow->getUpdatedAt());
    }

    #[Test]
    public function directionConstantsExposeAllowedValues(): void
    {
        self::assertSame('inbound', DoraDataFlow::DIRECTION_INBOUND);
        self::assertSame('outbound', DoraDataFlow::DIRECTION_OUTBOUND);
        self::assertSame('bidirectional', DoraDataFlow::DIRECTION_BIDIRECTIONAL);
        self::assertSame(
            ['inbound', 'outbound', 'bidirectional'],
            DoraDataFlow::DIRECTIONS,
        );
    }

    #[Test]
    public function tenantAndSupplierGettersSetters(): void
    {
        $flow = new DoraDataFlow();
        $tenant = new Tenant();
        $supplier = new Supplier();

        $flow->setTenant($tenant)->setSupplier($supplier);

        self::assertSame($tenant, $flow->getTenant());
        self::assertSame($supplier, $flow->getSupplier());
    }

    #[Test]
    public function dataCategoriesAreNormalisedToTrimmedUniqueNonEmpty(): void
    {
        $flow = new DoraDataFlow();
        $flow->setDataCategories([
            ' PII ',
            'financial',
            'PII',         // duplicate after trim
            '',            // dropped
            '  ',          // dropped (only whitespace)
            'health',
        ]);

        self::assertSame(['PII', 'financial', 'health'], $flow->getDataCategories());
    }

    #[Test]
    public function securityMeasuresAreNormalisedToTrimmedUniqueNonEmpty(): void
    {
        $flow = new DoraDataFlow();
        $flow->setSecurityMeasures([
            'encryption_in_transit',
            ' encryption_at_rest ',
            'encryption_in_transit', // duplicate
            '',
        ]);

        self::assertSame(
            ['encryption_in_transit', 'encryption_at_rest'],
            $flow->getSecurityMeasures(),
        );
    }

    #[Test]
    public function receivingCountryIsUppercasedAndTrimmed(): void
    {
        $flow = new DoraDataFlow();
        $flow->setReceivingCountry(' us ');
        self::assertSame('US', $flow->getReceivingCountry());

        $flow->setReceivingCountry(null);
        self::assertNull($flow->getReceivingCountry());
    }

    #[Test]
    public function clearingCrossBorderAlsoClearsReceivingCountry(): void
    {
        $flow = new DoraDataFlow();
        $flow->setCrossBorder(true)->setReceivingCountry('GB');
        self::assertTrue($flow->isCrossBorder());
        self::assertSame('GB', $flow->getReceivingCountry());

        $flow->setCrossBorder(false);
        self::assertFalse($flow->isCrossBorder());
        self::assertNull(
            $flow->getReceivingCountry(),
            'Clearing crossBorder must reset receivingCountry for data consistency.',
        );
    }

    #[Test]
    public function settingCrossBorderTruePreservesReceivingCountry(): void
    {
        $flow = new DoraDataFlow();
        $flow->setReceivingCountry('CH')->setCrossBorder(true);
        self::assertTrue($flow->isCrossBorder());
        self::assertSame('CH', $flow->getReceivingCountry());
    }

    #[Test]
    public function directionAcceptsAllAllowedValues(): void
    {
        $flow = new DoraDataFlow();
        foreach (DoraDataFlow::DIRECTIONS as $dir) {
            $flow->setDirection($dir);
            self::assertSame($dir, $flow->getDirection());
        }
    }

    #[Test]
    public function preUpdateSetsUpdatedAt(): void
    {
        $flow = new DoraDataFlow();
        self::assertNull($flow->getUpdatedAt());

        $flow->preUpdate();

        self::assertInstanceOf(DateTimeImmutable::class, $flow->getUpdatedAt());
    }

    #[Test]
    public function displayLabelIncludesSupplierDirectionAndCategories(): void
    {
        $supplier = new Supplier();
        $supplier->setName('Acme ICT');

        $flow = new DoraDataFlow();
        $flow->setSupplier($supplier)
            ->setDirection(DoraDataFlow::DIRECTION_OUTBOUND)
            ->setDataCategories(['PII', 'financial']);

        $label = $flow->getDisplayLabel();
        self::assertStringContainsString('Acme ICT', $label);
        self::assertStringContainsString('outbound', $label);
        self::assertStringContainsString('PII', $label);
        self::assertStringContainsString('financial', $label);
    }

    #[Test]
    public function displayLabelHandlesNullSupplierAndEmptyCategories(): void
    {
        $flow = new DoraDataFlow();
        $label = $flow->getDisplayLabel();
        self::assertStringContainsString('Unknown supplier', $label);
        self::assertStringContainsString('outbound', $label);
        self::assertStringContainsString('?', $label);
    }

    #[Test]
    public function dataCategoriesAcceptCastableScalars(): void
    {
        // The setter casts via (string) so numeric/bool feeds normalise too.
        $flow = new DoraDataFlow();
        /** @phpstan-ignore-next-line — intentional non-string input */
        $flow->setDataCategories([1, '1', 'PII']);

        // After (string) cast 1 and '1' collapse to '1'.
        self::assertSame(['1', 'PII'], $flow->getDataCategories());
    }
}
