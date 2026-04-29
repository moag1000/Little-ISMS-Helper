<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\Asset;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Service\Import\GstoolXmlImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Phase-1 GSTOOL importer tests — Zielobjekt → Asset, Schutzbedarf → CIA.
 *
 * Covers:
 *   - analyse() returns correct preview without DB writes
 *   - apply() commits Assets with correct CIA mapping
 *   - re-import is idempotent (update instead of duplicate)
 *   - missing <name> is reported as an error row
 *   - schema validation rejects wrong root or version
 */
final class GstoolXmlImporterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GstoolXmlImporter $importer;
    private AssetRepository $assetRepository;
    private Tenant $tenant;
    private string $fixturePath;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        $this->em = $em;
        /** @var GstoolXmlImporter $importer */
        $importer = $c->get(GstoolXmlImporter::class);
        $this->importer = $importer;
        /** @var AssetRepository $assetRepository */
        $assetRepository = $c->get(AssetRepository::class);
        $this->assetRepository = $assetRepository;

        $this->em->getConnection()->beginTransaction();

        $suffix = bin2hex(random_bytes(4));
        $this->tenant = (new Tenant())
            ->setCode('gstool-' . $suffix)
            ->setName('GSTOOL Test ' . $suffix);
        $this->em->persist($this->tenant);
        $this->em->flush();

        $this->fixturePath = dirname(__DIR__, 2) . '/Fixtures/gstool/sample-zielobjekte-v1.xml';
        self::assertFileExists($this->fixturePath);
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            $connection = $this->em->getConnection();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->em->clear();
        }
        parent::tearDown();
    }

    #[Test]
    public function testAnalysePreviewsWithoutWrites(): void
    {
        $result = $this->importer->analyse($this->fixturePath, $this->tenant);

        self::assertNull($result['header_error']);
        self::assertSame(5, $result['summary']['new']);
        self::assertSame(0, $result['summary']['update']);
        self::assertSame(0, $result['summary']['error']);

        // No assets persisted by analyse().
        self::assertCount(0, $this->assetRepository->findBy(['tenant' => $this->tenant]));
    }

    #[Test]
    public function testZielobjektWithoutNameIsRecordedAsError(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gstool-test-');
        file_put_contents($tmp, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<gstool-export version="1.0">
  <zielobjekte>
    <zielobjekt id="ZO-bad" type="IT-System">
      <kurzbeschreibung>Missing name on purpose</kurzbeschreibung>
    </zielobjekt>
  </zielobjekte>
</gstool-export>
XML);
        try {
            $result = $this->importer->analyse($tmp, $this->tenant);
            self::assertNull($result['header_error']);
            self::assertSame(0, $result['summary']['new']);
            self::assertSame(1, $result['summary']['error']);
            self::assertSame('Zielobjekt without <name>', $result['rows'][0]['error']);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function testApplyImportsAssetsWithCorrectCiaMapping(): void
    {
        $result = $this->importer->apply(
            $this->fixturePath,
            $this->tenant,
            null,
            'sample-zielobjekte-v1.xml',
        );

        self::assertNull($result['header_error']);
        self::assertSame(5, $result['summary']['new']);

        $webserver = $this->assetRepository->findOneBy([
            'tenant' => $this->tenant,
            'name' => 'Webserver-Production',
        ]);
        self::assertInstanceOf(Asset::class, $webserver);
        self::assertSame('it_system', $webserver->getAssetType());
        self::assertSame('IT-Abteilung', $webserver->getOwner());
        self::assertSame('RZ-Nord', $webserver->getLocation());
        // hoch → 4, sehr hoch → 5
        self::assertSame(4, $webserver->getConfidentialityValue());
        self::assertSame(5, $webserver->getIntegrityValue());
        self::assertSame(4, $webserver->getAvailabilityValue());

        $crm = $this->assetRepository->findOneBy([
            'tenant' => $this->tenant,
            'name' => 'Customer-CRM',
        ]);
        self::assertSame('application', $crm->getAssetType());
        self::assertSame(2, $crm->getAvailabilityValue(), 'normal → 2');

        $room = $this->assetRepository->findOneBy([
            'tenant' => $this->tenant,
            'name' => 'Server-Raum-A',
        ]);
        self::assertSame('physical_facility', $room->getAssetType());
        self::assertSame(5, $room->getAvailabilityValue());

        $person = $this->assetRepository->findOneBy([
            'tenant' => $this->tenant,
            'name' => 'Administrator',
        ]);
        self::assertSame('personnel', $person->getAssetType());
    }

    #[Test]
    public function testReImportIsIdempotent(): void
    {
        $first = $this->importer->apply($this->fixturePath, $this->tenant, null, 'first.xml');
        self::assertSame(5, $first['summary']['new']);
        $countAfterFirst = count($this->assetRepository->findBy(['tenant' => $this->tenant]));

        $tenantId = $this->tenant->getId();
        $this->em->clear();
        $tenant = $this->em->find(Tenant::class, $tenantId);
        self::assertNotNull($tenant);

        $second = $this->importer->apply($this->fixturePath, $tenant, null, 'second.xml');
        self::assertSame(0, $second['summary']['new']);
        self::assertSame(5, $second['summary']['update']);

        $countAfterSecond = count($this->assetRepository->findBy(['tenant' => $tenant]));
        self::assertSame($countAfterFirst, $countAfterSecond, 'No new assets on re-import.');
    }

    #[Test]
    public function testRejectsWrongRootElement(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gstool-test-');
        file_put_contents($tmp, '<?xml version="1.0"?><wrong-root version="1.0"/>');
        try {
            $result = $this->importer->analyse($tmp, $this->tenant);
            self::assertNotNull($result['header_error']);
            self::assertStringContainsString('Root element must be <gstool-export>', $result['header_error']);
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function testRejectsUnsupportedVersion(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gstool-test-');
        file_put_contents($tmp, '<?xml version="1.0"?><gstool-export version="9.9"/>');
        try {
            $result = $this->importer->analyse($tmp, $this->tenant);
            self::assertNotNull($result['header_error']);
            self::assertStringContainsString('Unsupported gstool-export version', $result['header_error']);
        } finally {
            @unlink($tmp);
        }
    }
}
