<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\MrisVersionMigrationCommand;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\AuditLogger;
use App\Service\MrisMaturityService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests für app:mris:migrate-version: Diff- und Apply-Logik zwischen
 * MRIS-Versionen, Soft-Delete für entfernte MHCs, Audit-Trail und
 * Default-Sicherheitsverhalten (kein Apply ohne explizites --apply).
 *
 * Quelle MRIS-Konzepte: Peddi, R. (2026). MRIS v1.5. CC BY 4.0.
 */
#[AllowMockObjectsWithoutExpectations]
final class MrisVersionMigrationCommandTest extends TestCase
{
    private string $projectDir;
    private MockObject $entityManager;
    private MockObject $frameworkRepository;
    private MockObject $requirementRepository;
    private MockObject $auditLogger;

    /** @var array<string, ComplianceRequirement> */
    private array $requirementsByMhc = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/mris_version_migration_test_' . uniqid();
        mkdir($this->projectDir . '/fixtures/frameworks', 0755, true);
        mkdir($this->projectDir . '/fixtures/library/mappings', 0755, true);

        $this->writeFrameworkYaml('v1.5', [
            ['MHC-01', 'Old Title 01', 'desc 01', ['initial' => 'i', 'defined' => 'd', 'managed' => 'm']],
            ['MHC-02', 'Title 02',     'desc 02', ['initial' => 'i', 'defined' => 'd', 'managed' => 'm']],
            ['MHC-13', 'Title 13',     'desc 13', ['initial' => 'i', 'defined' => 'd', 'managed' => 'm']],
        ]);
        // v1.6: MHC-01 renamed (title changed), MHC-02 unchanged, MHC-13 removed,
        //       MHC-14 + MHC-15 added, MHC-02 maturity unchanged.
        $this->writeFrameworkYaml('v1.6', [
            ['MHC-01', 'New Title 01', 'desc 01 updated', ['initial' => 'i', 'defined' => 'd', 'managed' => 'm']],
            ['MHC-02', 'Title 02',     'desc 02', ['initial' => 'i', 'defined' => 'd', 'managed' => 'm-NEU']],
            ['MHC-14', 'Title 14',     'desc 14', ['initial' => 'i', 'defined' => 'd', 'managed' => 'm']],
            ['MHC-15', 'Title 15',     'desc 15', ['initial' => 'i', 'defined' => 'd', 'managed' => 'm']],
        ]);

        $this->entityManager         = $this->createMock(EntityManagerInterface::class);
        $this->frameworkRepository   = $this->createMock(ComplianceFrameworkRepository::class);
        $this->requirementRepository = $this->createMock(ComplianceRequirementRepository::class);
        $this->auditLogger           = $this->createMock(AuditLogger::class);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectDir);
    }

    // ------------------------------------------------------------------ //

    #[Test]
    public function testDryRunPrintsDiffAndDoesNotWrite(): void
    {
        $this->frameworkRepository->method('findOneBy')->willReturn($this->makeFramework('MRIS-v1.5'));
        $this->wireRequirementRepository(['MHC-01', 'MHC-02', 'MHC-13']);

        // KEINE Audit-Logs im Dry-Run.
        $this->auditLogger->expects($this->never())->method('logCustom');
        $this->entityManager->expects($this->never())->method('flush');

        $tester = $this->buildTester();
        $exit = $tester->execute(['--from' => 'v1.5', '--to' => 'v1.6']);
        $output = $tester->getDisplay();

        self::assertSame(0, $exit);
        self::assertStringContainsString('DRY-RUN', $output);
        self::assertStringContainsString('MRIS-Versions-Migration v1.5', $output);
        self::assertStringContainsString('MHC added', $output);
        self::assertStringContainsString('MHC-14', $output);
        self::assertStringContainsString('MHC-15', $output);
        self::assertStringContainsString('MHC removed', $output);
        self::assertStringContainsString('MHC-13', $output);
        self::assertStringContainsString('MHC renamed', $output);
        self::assertStringContainsString('MHC-01', $output);
        // Nummerierte Operationsliste:
        self::assertMatchesRegularExpression('/1\.\s+ADD\s+ComplianceRequirement\s+MHC-14/', $output);
        self::assertStringContainsString('Quelle: Peddi (2026) MRIS v1.5', $output);
    }

    #[Test]
    public function testApplyRequiresExplicitFlagAndWritesAddedRequirements(): void
    {
        $framework = $this->makeFramework('MRIS-v1.5');
        $this->frameworkRepository->method('findOneBy')->willReturn($framework);
        $this->wireRequirementRepository(['MHC-01', 'MHC-02', 'MHC-13']);

        $persisted = [];
        $this->entityManager->method('persist')->willReturnCallback(function ($req) use (&$persisted) {
            if ($req instanceof ComplianceRequirement) {
                $persisted[] = $req;
            }
        });

        $auditCalls = [];
        $this->auditLogger->method('logCustom')->willReturnCallback(
            function (string $action, string $entityType, ?int $entityId, ?array $oldValues, ?array $newValues, ?string $description) use (&$auditCalls) {
                $auditCalls[] = compact('action', 'entityType', 'description');
            }
        );

        $tester = $this->buildTester();
        $exit = $tester->execute(['--from' => 'v1.5', '--to' => 'v1.6', '--apply' => true]);
        $output = $tester->getDisplay();

        self::assertSame(0, $exit);
        self::assertStringContainsString('Modus: APPLY', $output);
        // 2 ADDs (MHC-14, MHC-15)
        self::assertCount(2, $persisted, 'Beide neuen MHCs müssen persistiert werden.');
        $persistedIds = array_map(fn(ComplianceRequirement $r) => $r->getRequirementId(), $persisted);
        self::assertContains('MHC-14', $persistedIds);
        self::assertContains('MHC-15', $persistedIds);

        // Audit-Logs: ADD (2) + UPDATE rename (1) + DEPRECATE (1) = 4. Maturity-Reset
        // wird vom MrisMaturityService selbst geloggt (nicht über logCustom hier).
        $migrationLogs = array_filter($auditCalls, fn(array $c) => $c['action'] === 'MrisVersionMigration');
        self::assertGreaterThanOrEqual(4, count($migrationLogs), 'Mind. 4 Audit-Einträge mit Action MrisVersionMigration.');
    }

    #[Test]
    public function testWithoutApplyFlagNoWritesEvenIfDryRunNotPassed(): void
    {
        $this->frameworkRepository->method('findOneBy')->willReturn($this->makeFramework('MRIS-v1.5'));
        $this->wireRequirementRepository(['MHC-01', 'MHC-02', 'MHC-13']);

        // Default (kein --dry-run, kein --apply) muss trotzdem NICHTS schreiben.
        $this->auditLogger->expects($this->never())->method('logCustom');
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $tester = $this->buildTester();
        $exit = $tester->execute(['--from' => 'v1.5', '--to' => 'v1.6']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('DRY-RUN', $tester->getDisplay());
    }

    #[Test]
    public function testRemovedMhcIsDeprecatedSoftAndNotDeleted(): void
    {
        $framework = $this->makeFramework('MRIS-v1.5');
        $this->frameworkRepository->method('findOneBy')->willReturn($framework);
        $this->wireRequirementRepository(['MHC-01', 'MHC-02', 'MHC-13']);

        // Kein DELETE darf ausgeführt werden.
        $this->entityManager->expects($this->never())->method('remove');

        $deprecateAuditCalls = [];
        $this->auditLogger->method('logCustom')->willReturnCallback(
            function (string $action, string $entityType, ?int $entityId, ?array $oldValues, ?array $newValues, ?string $description) use (&$deprecateAuditCalls) {
                if (is_array($newValues) && ($newValues['lifecycle_state'] ?? null) === 'deprecated') {
                    $deprecateAuditCalls[] = $description;
                }
            }
        );

        $tester = $this->buildTester();
        $tester->execute(['--from' => 'v1.5', '--to' => 'v1.6', '--apply' => true]);

        // MHC-13 (nur in v1.5, nicht in v1.6) muss als deprecated markiert sein.
        $req13 = $this->requirementsByMhc['MHC-13'];
        $mapping = $req13->getDataSourceMapping() ?? [];
        self::assertSame('deprecated', $mapping['lifecycle_state'] ?? null, 'MHC-13 muss lifecycle_state=deprecated haben.');
        self::assertSame('v1.6', $mapping['deprecated_in'] ?? null);

        self::assertNotEmpty($deprecateAuditCalls, 'Mind. ein Audit-Log mit lifecycle_state=deprecated.');
        self::assertStringContainsString('MHC-13', $deprecateAuditCalls[0]);
    }

    // ------------------------------------------------------------------ //
    // Helpers
    // ------------------------------------------------------------------ //

    /**
     * @param list<array{0:string,1:string,2:string,3:array<string,string>}> $entries
     */
    private function writeFrameworkYaml(string $version, array $entries): void
    {
        $reqs = [];
        foreach ($entries as [$id, $title, $desc, $stages]) {
            $reqs[] = [
                'requirement_id'  => $id,
                'title'           => $title,
                'description'     => $desc,
                'category'        => 'mythos_haertung',
                'priority'        => 'high',
                'iso_anchor'      => 'A.5.1',
                'maturity_levels' => $stages,
            ];
        }
        $yaml = [
            'schema_version' => '1.0',
            'framework' => [
                'code' => 'MRIS-' . $version,
                'name' => 'MRIS ' . $version,
                'version' => ltrim($version, 'v'),
            ],
            'requirements' => $reqs,
        ];
        file_put_contents(
            $this->projectDir . '/fixtures/frameworks/mris-' . $version . '.yaml',
            Yaml::dump($yaml, 6)
        );
    }

    private function makeFramework(string $code): ComplianceFramework
    {
        $f = new ComplianceFramework();
        $f->setCode($code);
        return $f;
    }

    /**
     * @param list<string> $existingMhcIds
     */
    private function wireRequirementRepository(array $existingMhcIds): void
    {
        $this->requirementsByMhc = [];
        $idCounter = 1000;
        foreach ($existingMhcIds as $mhcId) {
            $req = new ComplianceRequirement();
            $req->setRequirementId($mhcId);
            $req->setTitle($mhcId === 'MHC-01' ? 'Old Title 01' : 'Title ' . substr($mhcId, -2));
            $req->setDescription('desc ' . substr($mhcId, -2));
            $req->setPriority('high');
            // ID via Reflection setzen (simuliert persistierte Entity).
            $ref = new \ReflectionProperty(ComplianceRequirement::class, 'id');
            $ref->setValue($req, $idCounter++);
            $this->requirementsByMhc[$mhcId] = $req;
        }
        $this->requirementRepository->method('findOneBy')->willReturnCallback(
            function (array $criteria): ?ComplianceRequirement {
                $id = $criteria['requirementId'] ?? null;
                if (!is_string($id)) {
                    return null;
                }
                return $this->requirementsByMhc[$id] ?? null;
            }
        );
    }

    private function buildTester(): CommandTester
    {
        $maturityService = new MrisMaturityService($this->entityManager, $this->auditLogger);

        $command = new MrisVersionMigrationCommand(
            $this->entityManager,
            $this->frameworkRepository,
            $this->requirementRepository,
            $maturityService,
            $this->auditLogger,
            $this->projectDir,
        );

        $app = new Application();
        $app->addCommand($command);

        return new CommandTester($app->find('app:mris:migrate-version'));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
