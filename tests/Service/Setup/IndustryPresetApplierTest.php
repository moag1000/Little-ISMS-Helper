<?php

declare(strict_types=1);

namespace App\Tests\Service\Setup;

use App\Entity\ComplianceFramework;
use App\Entity\Control;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Enum\DocumentStatus;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ControlRepository;
use App\Repository\DocumentRepository;
use App\Service\AnnexAControlsBootstrapService;
use App\Service\ModuleConfigurationService;
use App\Service\Setup\IndustryPresetApplier;
use App\Service\Setup\SetupIndustryPresetService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Regression: applying a preset with initial_documents crashed — the
 * command and the admin controller looked documents up via
 * findOneBy(['title' => …]) although Document has no `title` field
 * ("Unrecognized field"), and the created Document/Control rows left
 * NOT NULL columns (filename, mimeType, filePath, description, category)
 * empty. All four shipped presets must apply cleanly and idempotently.
 */
#[AllowMockObjectsWithoutExpectations]
final class IndustryPresetApplierTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Preset Test ' . uniqid());
        $this->tenant->setCode('preset_' . uniqid());
        $this->em->persist($this->tenant);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if ($this->tenant !== null && $this->em->isOpen()) {
            $this->em->clear();
            $conn = $this->em->getConnection();
            $tenantId = $this->tenant->getId();
            $conn->executeStatement('DELETE FROM document WHERE tenant_id = ?', [$tenantId]);
            $conn->executeStatement('DELETE FROM control WHERE tenant_id = ?', [$tenantId]);
            $conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$tenantId]);
        }
        parent::tearDown();
    }

    private function applier(?ModuleConfigurationService $modules = null): IndustryPresetApplier
    {
        $container = static::getContainer();
        if ($modules === null) {
            // Never let the test write config/active_modules.yaml.
            $modules = $this->createMock(ModuleConfigurationService::class);
            $modules->method('getActiveModules')->willReturn([]);
        }

        return new IndustryPresetApplier(
            $this->em,
            $modules,
            $container->get(ComplianceFrameworkRepository::class),
            $container->get(ControlRepository::class),
            $container->get(DocumentRepository::class),
            $container->get(AnnexAControlsBootstrapService::class),
            $container->get(TranslatorInterface::class),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function presetIds(): iterable
    {
        foreach (glob(\dirname(__DIR__, 3) . '/fixtures/library/presets/*.yaml') ?: [] as $file) {
            yield basename($file, '.yaml') => [basename($file, '.yaml')];
        }
    }

    #[Test]
    #[DataProvider('presetIds')]
    public function shippedPresetCreatesControlsAndPlaceholderDocuments(string $presetId): void
    {
        $config = new SetupIndustryPresetService()->loadPreset($presetId);
        self::assertIsArray($config);
        self::assertNotEmpty($config['initial_documents'] ?? [], 'preset should ship initial documents');
        // Frameworks are instance-global; this test covers the tenant-owned part.
        unset($config['frameworks']);

        $result = $this->applier()->apply($presetId, $config, $this->tenant);

        self::assertSame(count($config['initial_controls'] ?? []), $result['controls_created']);
        self::assertSame(count($config['initial_documents']), $result['documents_created']);

        $this->em->clear();
        $tenant = $this->em->find(Tenant::class, $this->tenant->getId());
        $documents = $this->em->getRepository(Document::class)->findBy(['tenant' => $tenant]);
        self::assertCount(count($config['initial_documents']), $documents);

        $titles = array_map(static fn(Document $d): ?string => $d->getOriginalFilename(), $documents);
        foreach ($config['initial_documents'] as $ddef) {
            self::assertContains($ddef['title'], $titles);
        }
        foreach ($documents as $document) {
            self::assertSame(DocumentStatus::Draft->value, $document->getStatus());
            self::assertStringStartsWith('virtual:industry-preset/', (string) $document->getFilePath());
            self::assertStringContainsString((string) $document->getOriginalFilename(), (string) $document->getPolicyBody());
            self::assertSame(IndustryPresetApplier::DOCUMENT_ENTITY_TYPE, $document->getEntityType());
        }

        foreach ($this->em->getRepository(Control::class)->findBy(['tenant' => $tenant]) as $control) {
            self::assertNotSame('', (string) $control->getDescription());
            self::assertNotSame('', (string) $control->getCategory());
            self::assertTrue($control->isApplicable());
        }

        // Re-applying leaves existing records alone.
        $again = $this->applier()->apply($presetId, $config, $tenant);
        self::assertSame(0, $again['controls_created']);
        self::assertSame(0, $again['documents_created']);
    }

    #[Test]
    public function annexAControlTakesCatalogTextAndPresetStatus(): void
    {
        $this->applier()->apply('test', [
            'initial_controls' => [
                ['identifier' => 'A.5.1', 'framework' => 'ISO27001', 'name' => 'Policies', 'implementation_status' => 'planned'],
                ['identifier' => 'NET.4.1.A1', 'framework' => 'BSI_GRUNDSCHUTZ', 'name' => 'Netz', 'implementation_status' => 'implemented'],
            ],
        ], $this->tenant);

        $repo = $this->em->getRepository(Control::class);
        $iso = $repo->findOneBy(['tenant' => $this->tenant, 'controlId' => 'A.5.1']);
        self::assertInstanceOf(Control::class, $iso);
        self::assertSame('Organizational', $iso->getCategory());
        self::assertStringStartsWith('Information security policy', (string) $iso->getDescription());
        self::assertSame('planned', $iso->getImplementationStatus());

        $bsi = $repo->findOneBy(['tenant' => $this->tenant, 'controlId' => 'NET.4.1.A1']);
        self::assertInstanceOf(Control::class, $bsi);
        self::assertSame('BSI_GRUNDSCHUTZ', $bsi->getCategory());
        self::assertSame('Netz', $bsi->getDescription());
    }

    #[Test]
    public function withoutTenantOnlyModulesAndFrameworksAreApplied(): void
    {
        $code = 'PRESET_TEST_' . strtoupper(substr(uniqid(), -8));
        $modules = $this->createMock(ModuleConfigurationService::class);
        $modules->method('getActiveModules')->willReturn(['core']);
        $modules->expects($this->once())->method('saveActiveModules')->with(['core', 'privacy']);

        try {
            $result = $this->applier($modules)->apply('test', [
                'modules' => ['core', 'privacy'],
                'frameworks' => [['code' => $code, 'priority' => 'primary', 'activate' => true]],
                'initial_controls' => [['identifier' => 'A.5.1', 'name' => 'Policies']],
                'initial_documents' => [['title' => 'Policy', 'type' => 'policy']],
            ], null);

            self::assertSame(['privacy'], $result['modules_activated']);
            self::assertSame(1, $result['frameworks_activated']);
            self::assertSame(0, $result['controls_created']);
            self::assertSame(0, $result['documents_created']);
            $framework = $this->em->getRepository(ComplianceFramework::class)->findOneBy(['code' => $code]);
            self::assertInstanceOf(ComplianceFramework::class, $framework);
            self::assertTrue($framework->isMandatory());
        } finally {
            $this->em->getConnection()->executeStatement('DELETE FROM compliance_framework WHERE code = ?', [$code]);
        }
    }
}
