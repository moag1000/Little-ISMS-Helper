<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Command\LoadIctProviderLibraryCommand;
use App\Entity\IctProviderLibrary;
use App\Entity\Tenant;
use App\Service\IctProviderLibraryService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-NEU — ICT-provider library: apply-service mapping + curated catalogue shape.
 * DB-free.
 */
final class IctProviderLibraryServiceTest extends TestCase
{
    #[Test]
    public function buildSupplierPrefillsFromLibraryEntryAndFlagsDoraRelevant(): void
    {
        $entry = (new IctProviderLibrary())
            ->setCode('aws')
            ->setName('Amazon Web Services')
            ->setCategory(IctProviderLibrary::CATEGORY_CLOUD_IAAS)
            ->setHeadquartersCountry('US')
            ->setServiceType('Public cloud IaaS')
            ->setDefaultCriticality('critical')
            ->setEeaHosted(true);

        $supplier = (new IctProviderLibraryService())->buildSupplier($entry, new Tenant());

        self::assertSame('Amazon Web Services', $supplier->getName());
        self::assertSame('US', $supplier->getCountryOfHeadOffice());
        self::assertSame('critical', $supplier->getCriticality());
        self::assertTrue($supplier->isDoraRelevant());
        self::assertNull($supplier->getId(), 'apply must return an UNPERSISTED Supplier for the review flow');
    }

    #[Test]
    public function criticalityHintMapsToSupplierScale(): void
    {
        $svc = new IctProviderLibraryService();
        $base = (new IctProviderLibrary())->setCode('x')->setName('X');

        self::assertSame('critical', $svc->buildSupplier((clone $base)->setDefaultCriticality('critical'), new Tenant())->getCriticality());
        self::assertSame('medium', $svc->buildSupplier((clone $base)->setDefaultCriticality('important'), new Tenant())->getCriticality());
        self::assertSame('low', $svc->buildSupplier((clone $base)->setDefaultCriticality('standard'), new Tenant())->getCriticality());
    }

    #[Test]
    public function catalogueShipsDistinctCodesWithValidCategories(): void
    {
        $ref = new ReflectionClass(LoadIctProviderLibraryCommand::class);
        /** @var array<string, array{string,string,?string,?string,string,bool}> $providers */
        $providers = $ref->getConstant('PROVIDERS');

        self::assertGreaterThanOrEqual(15, count($providers), 'Library should ship a useful starter set');
        self::assertSame(count($providers), count(array_unique(array_keys($providers))), 'codes must be unique');

        $validCategories = [
            IctProviderLibrary::CATEGORY_CLOUD_IAAS, IctProviderLibrary::CATEGORY_CLOUD_SAAS,
            IctProviderLibrary::CATEGORY_NETWORK, IctProviderLibrary::CATEGORY_IDENTITY,
            IctProviderLibrary::CATEGORY_DATA, IctProviderLibrary::CATEGORY_SECURITY,
            IctProviderLibrary::CATEGORY_PAYMENT, IctProviderLibrary::CATEGORY_COMMS,
        ];
        foreach ($providers as $code => [$name, $category]) {
            self::assertContains($category, $validCategories, "Provider {$code} has an invalid category");
        }
    }
}
