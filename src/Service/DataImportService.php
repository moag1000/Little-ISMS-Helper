<?php

namespace App\Service;

use Exception;
use App\Entity\Asset;
use App\Entity\Risk;
use App\Entity\Control;
use App\Entity\Incident;
use App\Entity\BusinessProcess;
use App\Entity\InternalAudit;
use App\Entity\Training;
use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Consent;
use App\Entity\CrisisTeam;
use App\Entity\DataBreach;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\DataSubjectRequest;
use App\Entity\Document;
use App\Entity\InterestedParty;
use App\Entity\ISMSObjective;
use App\Entity\Location;
use App\Entity\ManagementReview;
use App\Entity\Person;
use App\Entity\ProcessingActivity;
use App\Entity\RiskAppetite;
use App\Entity\SampleDataImport;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\SampleDataImportRepository;
use App\Repository\TenantRepository;
use DateTime;
use ReflectionClass;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
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

    /**
     * Laufzeit-Kontext eines aktuell aktiven Sample-Imports.
     * Wird vor importFromFile() gesetzt, damit importEntities() die
     * angelegten Entities tracken kann. Null zwischen Imports.
     */
    private ?array $activeImportContext = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KernelInterface $kernel,
        private readonly ModuleConfigurationService $moduleConfigurationService,
        private readonly TenantRepository $tenantRepository,
        private readonly SampleDataImportRepository $sampleImportRepository,
        private readonly string $projectDir
    ) {
    }

    /**
     * Liefert den für Import-Zwecke zu verwendenden Tenant.
     * Im Setup-Wizard ist das der erste (einzige) Tenant — typischerweise
     * 'default' aus step6_organisation_info.
     */
    private function resolveImportTenant(): ?Tenant
    {
        return $this->tenantRepository->findOneBy(['code' => 'default'])
            ?? $this->tenantRepository->findOneBy([]);
    }

    /**
     * Importiert Basis-Daten
     */
    public function importBaseData(array $activeModules): array
    {
        $this->importLog = [];
        $baseData = $this->moduleConfigurationService->getBaseData();
        $results = [];

        foreach ($baseData as $data) {
            $requiredModules = $data['required_modules'] ?? [];

            // Prüfe ob erforderliche Module aktiv sind
            $canImport = true;
            foreach ($requiredModules as $requiredModule) {
                if (!in_array($requiredModule, $activeModules)) {
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
    public function importSampleData(array $selectedSamples, array $activeModules, ?Tenant $tenant = null, ?User $importedBy = null): array
    {
        $this->importLog = [];
        $sampleData = $this->moduleConfigurationService->getSampleData();
        $results = [];
        $tenant = $tenant ?? $this->resolveImportTenant();

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

            foreach ($requiredModules as $requiredModule) {
                if (!in_array($requiredModule, $activeModules)) {
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
                // Commands tracken wir (noch) nicht — keine Entity-IDs zurück.
                $result = $this->executeCommand($data['command']);
                $results[] = [
                    'name' => $data['name'],
                    'status' => $result['success'] ? 'success' : 'error',
                    'message' => $result['message'],
                    'output' => $result['output'] ?? null,
                    'removable' => false,
                ];
            } elseif (isset($data['file'])) {
                $this->activeImportContext = [
                    'sampleKey' => $sampleKey,
                    'tenant' => $tenant,
                    'importedBy' => $importedBy,
                ];
                $result = $this->importFromFile($data['file']);
                $this->activeImportContext = null;
                $results[] = [
                    'name' => $data['name'],
                    'status' => $result['success'] ? 'success' : 'error',
                    'message' => $result['message'],
                    'removable' => true,
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

            $arrayInput = new ArrayInput([
                'command' => $commandName,
            ]);

            $bufferedOutput = new BufferedOutput();
            $returnCode = $application->run($arrayInput, $bufferedOutput);

            $outputContent = $bufferedOutput->fetch();
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
        $ctx = $this->activeImportContext;
        $tenant = $ctx['tenant'] ?? $this->resolveImportTenant();
        $created = [];

        foreach ($data as $entityType => $entities) {
            $entityClass = $this->resolveEntityClass($entityType);

            if (!$entityClass) {
                $this->addLog("Unknown entity type: {$entityType}");
                continue;
            }

            foreach ($entities as $entityData) {
                try {
                    $entity = $this->createEntity($entityClass, $entityData);
                    // Setup-Imports ohne User-Session → Tenant aus DB setzen,
                    // sonst landen Entities mit tenant_id=NULL und sind durch
                    // TenantFilter im Modul unsichtbar (nur in Count-Queries
                    // ohne Filter noch zählbar).
                    if ($tenant && method_exists($entity, 'setTenant') && method_exists($entity, 'getTenant') && $entity->getTenant() === null) {
                        $entity->setTenant($tenant);
                    }
                    $this->entityManager->persist($entity);
                    $created[] = $entity;
                    $count++;
                } catch (Exception $e) {
                    $this->addLog("Error creating entity {$entityClass}: " . $e->getMessage());
                }
            }
        }

        $this->entityManager->flush();

        // Tracking-Records nach dem Flush (damit Entity-IDs existieren).
        if ($ctx !== null && $count > 0) {
            foreach ($created as $entity) {
                if (!method_exists($entity, 'getId') || $entity->getId() === null) {
                    continue;
                }
                $track = new SampleDataImport();
                $track->setSampleKey($ctx['sampleKey']);
                $track->setEntityClass($entity::class);
                $track->setEntityId((int) $entity->getId());
                $track->setTenant($ctx['tenant']);
                $track->setImportedBy($ctx['importedBy']);
                $this->entityManager->persist($track);
            }
            $this->entityManager->flush();
        }

        $this->addLog("Imported {$count} entities");

        return $count;
    }

    /**
     * Entfernt alle via importSampleData angelegten Entities eines Sample-Keys
     * für den angegebenen Tenant. Löscht Entities in umgekehrter Insert-Reihenfolge
     * (neueste zuerst), damit Self-FK-Ketten keine Probleme machen.
     *
     * @return array{success: bool, removed: int, errors: array<int, string>}
     */
    public function removeSampleData(string $sampleKey, Tenant $tenant): array
    {
        $trackedImports = $this->sampleImportRepository->findByKey($sampleKey, $tenant);
        // Umgekehrt sortieren → FK-sicherer, neueste Inserts zuerst gelöscht.
        usort($trackedImports, fn(SampleDataImport $a, SampleDataImport $b) => $b->getId() <=> $a->getId());

        $removed = 0;
        $errors = [];

        foreach ($trackedImports as $track) {
            try {
                $entity = $this->entityManager->find($track->getEntityClass(), $track->getEntityId());
                if ($entity !== null) {
                    $this->entityManager->remove($entity);
                    $removed++;
                }
                $this->entityManager->remove($track);
            } catch (Exception $e) {
                $errors[] = sprintf('%s#%d: %s', $track->getEntityClass(), $track->getEntityId(), $e->getMessage());
            }
        }

        try {
            $this->entityManager->flush();
        } catch (Exception $e) {
            $errors[] = 'Flush: ' . $e->getMessage();
            return ['success' => false, 'removed' => 0, 'errors' => $errors];
        }

        return ['success' => empty($errors), 'removed' => $removed, 'errors' => $errors];
    }

    /**
     * Löst Entity-Class-Namen auf
     */
    private function resolveEntityClass(string $entityType): ?string
    {
        $mapping = [
            'assets' => Asset::class,
            'risks' => Risk::class,
            'controls' => Control::class,
            'incidents' => Incident::class,
            'business_processes' => BusinessProcess::class,
            'audits' => InternalAudit::class,
            'trainings' => Training::class,
            'compliance_frameworks' => ComplianceFramework::class,
            'compliance_requirements' => ComplianceRequirement::class,
            // Phase 2 additions
            'documents' => Document::class,
            'management_reviews' => ManagementReview::class,
            'processing_activities' => ProcessingActivity::class,
            'data_breaches' => DataBreach::class,
            'consents' => Consent::class,
            'dpias' => DataProtectionImpactAssessment::class,
            'data_subject_requests' => DataSubjectRequest::class,
            'bc_plans' => BusinessContinuityPlan::class,
            'business_continuity_plans' => BusinessContinuityPlan::class,
            'bc_exercises' => BCExercise::class,
            'crisis_teams' => CrisisTeam::class,
            'suppliers' => Supplier::class,
            'locations' => Location::class,
            'people' => Person::class,
            'interested_parties' => InterestedParty::class,
            'objectives' => ISMSObjective::class,
            'risk_appetites' => RiskAppetite::class,
            'risk_appetite' => RiskAppetite::class,
        ];

        return $mapping[$entityType] ?? null;
    }

    /**
     * Natural-Key-Feld pro Entity-Typ — wird für ref:-Lookups genutzt.
     * Fehlt ein Mapping → kein ref-Resolving möglich.
     *
     * @return array<string, string>
     */
    private function referenceNaturalKeys(): array
    {
        return [
            'asset' => 'name',
            'risk' => 'title',
            'incident' => 'title',
            'control' => 'identifier',
            'business_process' => 'name',
            'processing_activity' => 'name',
            'supplier' => 'name',
            'location' => 'name',
            'person' => 'fullName',
            'document' => 'filename',
            'internal_audit' => 'title',
            'training' => 'title',
            'management_review' => 'title',
            'interested_party' => 'name',
            'ismsobjective' => 'title',
            'risk_appetite' => 'category',
            'bc_plan' => 'name',
            'bc_exercise' => 'name',
            'crisis_team' => 'teamName',
            'compliance_framework' => 'code',
            'compliance_requirement' => 'identifier',
            'dpia' => 'title',
            'data_breach' => 'referenceNumber',
            'consent' => 'dataSubjectIdentifier',
            'data_subject_request' => 'referenceNumber',
            'tenant' => 'code',
            'user' => 'email',
        ];
    }

    /**
     * Löst Strings der Form "ref:<type>:<natural-key>" zur Entity auf.
     * Nutzt das Natural-Key-Feld aus referenceNaturalKeys(). Für unbekannte
     * Typen oder fehlende Referenzen: null + Log-Eintrag.
     */
    private function resolveReference(string $value): ?object
    {
        if (!str_starts_with($value, 'ref:')) {
            return null;
        }
        $parts = explode(':', $value, 3);
        if (count($parts) !== 3) {
            return null;
        }
        [$prefix, $type, $key] = $parts;
        $entityClass = $this->resolveEntityClass($type)
            ?? $this->resolveEntityClass($type . 's')
            ?? null;
        if ($entityClass === null) {
            $this->addLog("Unknown ref-type '{$type}' in reference '{$value}'");
            return null;
        }
        $keys = $this->referenceNaturalKeys();
        $keyField = $keys[$type] ?? $keys[rtrim($type, 's')] ?? null;
        if ($keyField === null) {
            $this->addLog("No natural-key field registered for ref-type '{$type}'");
            return null;
        }

        $entity = $this->entityManager->getRepository($entityClass)->findOneBy([$keyField => $key]);
        if ($entity === null) {
            $this->addLog("Reference not found: {$entityClass}.{$keyField} = '{$key}' (from '{$value}')");
        }
        return $entity;
    }

    /**
     * Erstellt Entity aus Array-Daten.
     * Unterstützt scalar values, Datum-Strings (Reflection auf Setter-Typ),
     * ref:-Strings (Entity-Lookup per Natural-Key) und Collections.
     */
    private function createEntity(string $entityClass, array $data): object
    {
        $entity = new $entityClass();
        $shortName = (new \ReflectionClass($entity))->getShortName();

        foreach ($data as $property => $value) {
            $propertyUcFirst = ucfirst((string) $property);
            // snake_case → camelCase Variante (z. B. `inherent_risk_level`
            // → `setInherentRiskLevel`). Ergänzt direkten Setter und
            // Entity-Prefix-Setter um eine dritte Auflösungs-Stufe.
            $camelCase = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', (string) $property))));
            $camelUcFirst = ucfirst($camelCase);

            // Setter-Kandidaten: direkter Name, Entity-Prefix-Variante (z. B.
            // `type` → `setAssetType` auf Asset), snake-to-camel, bekannter Alias.
            $setterCandidates = [
                'set' . $propertyUcFirst,
                'set' . $shortName . $propertyUcFirst,
                'set' . $camelUcFirst,
                'set' . $shortName . $camelUcFirst,
            ];
            // Aliases: YAML-Convention vs. Entity-Konvention
            $aliases = [
                'name' => 'setTitle',  // Risk/Incident verwenden title statt name
                'date' => 'setOccurredAt',
            ];
            if (isset($aliases[(string) $property])) {
                $setterCandidates[] = $aliases[(string) $property];
            }

            $setter = null;
            foreach ($setterCandidates as $cand) {
                if (method_exists($entity, $cand)) {
                    $setter = $cand;
                    break;
                }
            }
            $adder = 'add' . ucfirst(rtrim((string) $property, 's'));

            // ref:-Syntax auflösen (single oder list of refs)
            if (is_string($value) && str_starts_with($value, 'ref:')) {
                $resolved = $this->resolveReference($value);
                if ($resolved === null) {
                    continue;  // Log in resolveReference
                }
                $value = $resolved;
            } elseif (is_array($value) && $this->isRefList($value)) {
                // Collection-Refs: nutze adder() sofern vorhanden
                if (method_exists($entity, $adder)) {
                    foreach ($value as $ref) {
                        $resolved = $this->resolveReference((string) $ref);
                        if ($resolved !== null) {
                            $entity->$adder($resolved);
                        }
                    }
                    continue;
                }
            }

            if ($setter === null) {
                $this->addLog("No setter found on {$entityClass} for property '{$property}' (tried: " . implode(', ', $setterCandidates) . ')');
                continue;
            }

            // Datum-Strings: je nach Setter-Parametertyp DateTimeImmutable oder
            // DateTime konstruieren. Bei DateTimeInterface bevorzugen wir
            // DateTimeImmutable, weil Doctrine-Columns mit Type DATETIME_IMMUTABLE
            // explicit DateTimeImmutable verlangen (Doctrine wirft sonst Conversion-
            // Error, siehe RiskAppetite::approvedAt etc.).
            if (is_string($value) && strtotime($value) !== false) {
                try {
                    $reflection = new \ReflectionMethod($entity, $setter);
                    $paramType = $reflection->getParameters()[0]?->getType();
                    $typeName = $paramType instanceof \ReflectionNamedType ? $paramType->getName() : null;
                    if ($typeName === \DateTimeImmutable::class || $typeName === \DateTimeInterface::class) {
                        $value = new \DateTimeImmutable($value);
                    } elseif ($typeName === \DateTime::class) {
                        $value = new DateTime($value);
                    }
                } catch (\ReflectionException) {
                    // Reflection konnte Typ nicht lesen — String durchlassen, setter wirft ggf.
                }
            }

            try {
                $entity->$setter($value);
            } catch (\TypeError $e) {
                $this->addLog("Skipped {$entityClass}::{$setter} — type mismatch: " . $e->getMessage());
            }
        }

        return $entity;
    }

    /**
     * Prüft ob ein Array nur ref:-Strings enthält (für Collection-Properties).
     */
    private function isRefList(array $arr): bool
    {
        if (empty($arr) || !array_is_list($arr)) {
            return false;
        }
        foreach ($arr as $item) {
            if (!is_string($item) || !str_starts_with($item, 'ref:')) {
                return false;
            }
        }
        return true;
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
                'initialized' => $missingTables === [],
                'total_tables' => count($tables),
                'missing_tables' => $missingTables,
                'existing_tables' => array_intersect($requiredTables, $tables),
            ];
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
        $module = $this->moduleConfigurationService->getModule($moduleKey);

        if (!$module) {
            return [
                'success' => false,
                'error' => 'Modul nicht gefunden',
            ];
        }

        $entities = $module['entities'] ?? [];
        $exportData = [];

        foreach ($entities as $entity) {
            $entityClass = $this->resolveEntityClass(strtolower((string) $entity) . 's');

            if (!$entityClass) {
                continue;
            }

            $repository = $this->entityManager->getRepository($entityClass);
            $records = $repository->findAll();

            $exportData[$entity] = array_map($this->entityToArray(...), $records);
        }

        return [
            'success' => true,
            'data' => $exportData,
            'count' => array_sum(array_map(count(...), $exportData)),
        ];
    }

    /**
     * Konvertiert Entity zu Array
     */
    private function entityToArray(object $entity): array
    {
        $reflectionClass = new ReflectionClass($entity);
        $data = [];

        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            $value = $reflectionProperty->getValue($entity);
            // Skip ID und Relations
            if ($reflectionProperty->getName() === 'id') {
                continue;
            }
            if (is_object($value)) {
                continue;
            }

            $data[$reflectionProperty->getName()] = $value;
        }

        return $data;
    }
}
