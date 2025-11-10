<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Data Import Service
 *
 * Manages the import of base data and sample data for ISMS modules.
 * Supports both command-based and file-based import mechanisms.
 *
 * Features:
 * - Base data import (ISO 27001 controls, compliance requirements)
 * - Sample data import for demo/testing purposes
 * - Module-aware import (respects active modules)
 * - Command execution for data loading
 * - YAML-based file imports
 * - Import logging and result tracking
 *
 * Workflow:
 * 1. Check module dependencies
 * 2. Execute configured import commands
 * 3. Load data from YAML files
 * 4. Track results and errors
 */
class DataImportService
{
    private array $importLog = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KernelInterface $kernel,
        private readonly ModuleConfigurationService $moduleConfigService,
        private readonly string $projectDir
    ) {
    }

    /**
     * Importiert Basis-Daten
     */
    public function importBaseData(array $activeModules): array
    {
        $this->importLog = [];
        $baseData = $this->moduleConfigService->getBaseData();
        $results = [];

        foreach ($baseData as $data) {
            $requiredModules = $data['required_modules'] ?? [];

            // Prüfe ob erforderliche Module aktiv sind
            $canImport = true;
            foreach ($requiredModules as $module) {
                if (!in_array($module, $activeModules)) {
                    $canImport = false;
                    break;
                }
            }

            if (!$canImport) {
                $results[] = [
                    'name' => $data['name'],
                    'status' => 'skipped',
                    'message' => 'Erforderliche Module nicht aktiv',
                ];
                continue;
            }

            // Führe Import durch
            if (isset($data['command'])) {
                $result = $this->executeCommand($data['command']);
                $results[] = [
                    'name' => $data['name'],
                    'status' => $result['success'] ? 'success' : 'error',
                    'message' => $result['message'],
                    'output' => $result['output'] ?? null,
                ];
            } elseif (isset($data['file'])) {
                $result = $this->importFromFile($data['file']);
                $results[] = [
                    'name' => $data['name'],
                    'status' => $result['success'] ? 'success' : 'error',
                    'message' => $result['message'],
                ];
            }
        }

        return [
            'results' => $results,
            'log' => $this->importLog,
        ];
    }

    /**
     * Importiert Beispiel-Daten
     */
    public function importSampleData(array $selectedSamples, array $activeModules): array
    {
        $this->importLog = [];
        $sampleData = $this->moduleConfigService->getSampleData();
        $results = [];

        foreach ($selectedSamples as $sampleKey => $selected) {
            if (!$selected) {
                continue;
            }

            $data = $sampleData[$sampleKey] ?? null;

            if (!$data) {
                $results[] = [
                    'name' => $sampleKey,
                    'status' => 'error',
                    'message' => 'Beispiel-Daten nicht gefunden',
                ];
                continue;
            }

            // Prüfe Modul-Abhängigkeiten
            $requiredModules = $data['required_modules'] ?? [];
            $canImport = true;

            foreach ($requiredModules as $module) {
                if (!in_array($module, $activeModules)) {
                    $canImport = false;
                    break;
                }
            }

            if (!$canImport) {
                $results[] = [
                    'name' => $data['name'],
                    'status' => 'skipped',
                    'message' => 'Erforderliche Module nicht aktiv',
                ];
                continue;
            }

            // Führe Import durch
            if (isset($data['command'])) {
                $result = $this->executeCommand($data['command']);
                $results[] = [
                    'name' => $data['name'],
                    'status' => $result['success'] ? 'success' : 'error',
                    'message' => $result['message'],
                    'output' => $result['output'] ?? null,
                ];
            } elseif (isset($data['file'])) {
                $result = $this->importFromFile($data['file']);
                $results[] = [
                    'name' => $data['name'],
                    'status' => $result['success'] ? 'success' : 'error',
                    'message' => $result['message'],
                ];
            }
        }

        return [
            'results' => $results,
            'log' => $this->importLog,
        ];
    }

    /**
     * Führt einen Symfony Console Command aus
     */
    private function executeCommand(string $commandName): array
    {
        try {
            $application = new Application($this->kernel);
            $application->setAutoExit(false);

            $input = new ArrayInput([
                'command' => $commandName,
            ]);

            $output = new BufferedOutput();
            $returnCode = $application->run($input, $output);

            $outputContent = $output->fetch();
            $this->addLog("Command '{$commandName}' executed with return code {$returnCode}");
            $this->addLog($outputContent);

            return [
                'success' => $returnCode === 0,
                'message' => $returnCode === 0
                    ? "Command '{$commandName}' erfolgreich ausgeführt"
                    : "Command '{$commandName}' fehlgeschlagen (Code: {$returnCode})",
                'output' => $outputContent,
                'return_code' => $returnCode,
            ];
        } catch (\Exception $e) {
            $this->addLog("Error executing command '{$commandName}': " . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Fehler beim Ausführen: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Importiert Daten aus YAML-Datei
     */
    private function importFromFile(string $file): array
    {
        try {
            $filePath = $this->projectDir . '/' . $file;

            if (!file_exists($filePath)) {
                $this->addLog("File not found: {$filePath}");

                return [
                    'success' => false,
                    'message' => "Datei nicht gefunden: {$file}",
                ];
            }

            $data = Yaml::parseFile($filePath);
            $this->addLog("Loaded data from {$filePath}");

            // Importiere Entitäten basierend auf Datenstruktur
            $imported = $this->importEntities($data);

            return [
                'success' => true,
                'message' => "{$imported} Datensätze importiert",
                'count' => $imported,
            ];
        } catch (\Exception $e) {
            $this->addLog("Error importing from file '{$file}': " . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Fehler beim Importieren: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Importiert Entitäten aus Array
     */
    private function importEntities(array $data): int
    {
        $count = 0;

        foreach ($data as $entityType => $entities) {
            $entityClass = $this->resolveEntityClass($entityType);

            if (!$entityClass) {
                $this->addLog("Unknown entity type: {$entityType}");
                continue;
            }

            foreach ($entities as $entityData) {
                try {
                    $entity = $this->createEntity($entityClass, $entityData);
                    $this->entityManager->persist($entity);
                    $count++;
                } catch (\Exception $e) {
                    $this->addLog("Error creating entity {$entityClass}: " . $e->getMessage());
                }
            }
        }

        $this->entityManager->flush();
        $this->addLog("Imported {$count} entities");

        return $count;
    }

    /**
     * Löst Entity-Class-Namen auf
     */
    private function resolveEntityClass(string $entityType): ?string
    {
        $mapping = [
            'assets' => \App\Entity\Asset::class,
            'risks' => \App\Entity\Risk::class,
            'controls' => \App\Entity\Control::class,
            'incidents' => \App\Entity\Incident::class,
            'business_processes' => \App\Entity\BusinessProcess::class,
            'audits' => \App\Entity\InternalAudit::class,
            'trainings' => \App\Entity\Training::class,
            'compliance_frameworks' => \App\Entity\ComplianceFramework::class,
            'compliance_requirements' => \App\Entity\ComplianceRequirement::class,
        ];

        return $mapping[$entityType] ?? null;
    }

    /**
     * Erstellt Entity aus Array-Daten
     */
    private function createEntity(string $entityClass, array $data): object
    {
        $entity = new $entityClass();

        foreach ($data as $property => $value) {
            $setter = 'set' . ucfirst($property);

            if (method_exists($entity, $setter)) {
                // Handle DateTime
                if ($value instanceof \DateTime || (is_string($value) && strtotime($value))) {
                    $value = is_string($value) ? new \DateTime($value) : $value;
                }

                $entity->$setter($value);
            }
        }

        return $entity;
    }

    /**
     * Prüft Datenbank-Status
     */
    public function checkDatabaseStatus(): array
    {
        try {
            $connection = $this->entityManager->getConnection();
            $schemaManager = $connection->createSchemaManager();

            // Prüfe ob Tabellen existieren
            $tables = $schemaManager->listTableNames();

            $requiredTables = [
                'asset',
                'risk',
                'control',
                'incident',
                'internal_audit',
                'business_process',
                'compliance_framework',
            ];

            $missingTables = array_diff($requiredTables, $tables);

            return [
                'initialized' => empty($missingTables),
                'total_tables' => count($tables),
                'missing_tables' => $missingTables,
                'existing_tables' => array_intersect($requiredTables, $tables),
            ];
        } catch (\Exception $e) {
            return [
                'initialized' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Führt Datenbank-Migrationen aus
     */
    public function runMigrations(): array
    {
        try {
            $result = $this->executeCommand('doctrine:migrations:migrate');

            return [
                'success' => $result['success'],
                'message' => $result['message'],
                'output' => $result['output'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Fehler beim Ausführen der Migrationen: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fügt Log-Eintrag hinzu
     */
    private function addLog(string $message): void
    {
        $this->importLog[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => $message,
        ];
    }

    /**
     * Gibt Import-Log zurück
     */
    public function getImportLog(): array
    {
        return $this->importLog;
    }

    /**
     * Exportiert Modul-Daten (für Backup)
     */
    public function exportModuleData(string $moduleKey): array
    {
        $module = $this->moduleConfigService->getModule($moduleKey);

        if (!$module) {
            return [
                'success' => false,
                'error' => 'Modul nicht gefunden',
            ];
        }

        $entities = $module['entities'] ?? [];
        $exportData = [];

        foreach ($entities as $entityName) {
            $entityClass = $this->resolveEntityClass(strtolower($entityName) . 's');

            if (!$entityClass) {
                continue;
            }

            $repository = $this->entityManager->getRepository($entityClass);
            $records = $repository->findAll();

            $exportData[$entityName] = array_map(function ($record) {
                return $this->entityToArray($record);
            }, $records);
        }

        return [
            'success' => true,
            'data' => $exportData,
            'count' => array_sum(array_map('count', $exportData)),
        ];
    }

    /**
     * Konvertiert Entity zu Array
     */
    private function entityToArray(object $entity): array
    {
        $reflection = new \ReflectionClass($entity);
        $data = [];

        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($entity);

            // Skip ID und Relations
            if ($property->getName() === 'id' || is_object($value)) {
                continue;
            }

            $data[$property->getName()] = $value;
        }

        return $data;
    }
}
