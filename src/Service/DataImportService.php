<?php

declare(strict_types=1);

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
use Doctrine\Persistence\ManagerRegistry;
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
     * Wird beim importFromFile()-Aufruf zurückgesetzt und sammelt alle
     * ref:-Lookups, die nicht aufgelöst werden konnten. Wird in der
     * Result-Message surfaced, damit der User merkt wenn er Beispiele in
     * einer Reihenfolge importiert die Abhängigkeiten verletzt
     * (z. B. Risks ohne vorher Assets).
     *
     * @var list<string>
     */
    private array $unresolvedRefs = [];

    /**
     * Laufzeit-Kontext eines aktuell aktiven Sample-Imports.
     * Wird vor importFromFile() gesetzt, damit importEntities() die
     * angelegten Entities tracken kann. Null zwischen Imports.
     */
    private ?array $activeImportContext = null;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private readonly KernelInterface $kernel,
        private readonly ModuleConfigurationService $moduleConfigurationService,
        private readonly TenantRepository $tenantRepository,
        private readonly SampleDataImportRepository $sampleImportRepository,
        private readonly string $projectDir,
        private readonly ?ManagerRegistry $managerRegistry = null
    ) {
    }

    /**
     * Wenn der EntityManager nach einer Constraint-Violation geschlossen
     * wurde, einen frischen vom ManagerRegistry holen, damit nachfolgende
     * Sample-Imports im selben Request nicht alle mit "EM is closed"
     * cascaden. Setzt $this->entityManager auf den frischen Manager um.
     */
    private function resetEntityManagerIfClosed(): void
    {
        if ($this->entityManager->isOpen() || $this->managerRegistry === null) {
            return;
        }
        $this->managerRegistry->resetManager();
        $fresh = $this->managerRegistry->getManager();
        if ($fresh instanceof EntityManagerInterface) {
            $this->entityManager = $fresh;
            $this->addLog('EntityManager re-opened after closure');
        }
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
                $this->resetEntityManagerIfClosed();
                // Tenant ggf. neu laden, falls EM zwischenzeitlich resettet wurde —
                // sonst löst Doctrine ihn als "new entity" und verlangt cascade=persist.
                $boundTenant = $tenant;
                if ($tenant !== null && $tenant->getId() !== null) {
                    $managed = $this->entityManager->find(get_class($tenant), $tenant->getId());
                    if ($managed instanceof Tenant) {
                        $boundTenant = $managed;
                    }
                }
                $this->activeImportContext = [
                    'sampleKey' => $sampleKey,
                    'tenant' => $boundTenant,
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

        // Nach Abschluss aller selected Samples: vorher unauflösbare Refs
        // nachträglich auflösen. Use-case: User importiert Risks zuerst,
        // dann Assets — die Risks haben jetzt asset_id=NULL, aber die
        // Assets sind in der DB. Backfill setzt die Verbindungen nach.
        $repaired = $this->repairUnresolvedReferences($tenant);
        if ($repaired > 0) {
            $results[] = [
                'name' => 'Backfill',
                'status' => 'success',
                'message' => sprintf('%d nachträgliche Beziehungen aufgelöst', $repaired),
                'removable' => false,
            ];
        }

        return [
            'results' => $results,
            'log' => $this->importLog,
        ];
    }

    /**
     * Iteriert alle bisher importierten Sample-Entities und versucht,
     * nicht-aufgelöste ref:-Beziehungen aus der zugehörigen YAML-Datei
     * jetzt zu setzen — falls die Ziel-Entity inzwischen importiert wurde.
     * Greift idempotent: setzt Werte nur wenn das aktuelle Property null
     * ist, ändert nichts an bereits gesetzten Beziehungen.
     */
    private function repairUnresolvedReferences(?Tenant $tenant): int
    {
        if ($tenant === null) {
            return 0;
        }
        $this->resetEntityManagerIfClosed();
        $tracking = $this->sampleImportRepository->findBy(['tenant' => $tenant]);
        if ($tracking === []) {
            return 0;
        }

        $sampleData = $this->moduleConfigurationService->getSampleData();
        $repaired = 0;

        // Build sampleKey → YAML data once.
        $loaded = [];
        foreach ($tracking as $t) {
            $key = $t->getSampleKey();
            if (!array_key_exists($key, $loaded)) {
                $cfg = $sampleData[$key] ?? $sampleData[(int) $key] ?? null;
                if ($cfg === null || !isset($cfg['file'])) {
                    $loaded[$key] = null;
                    continue;
                }
                $path = $this->projectDir . '/' . $cfg['file'];
                if (!file_exists($path)) {
                    $loaded[$key] = null;
                    continue;
                }
                try {
                    $loaded[$key] = Yaml::parseFile($path);
                } catch (\Throwable) {
                    $loaded[$key] = null;
                }
            }
        }

        foreach ($tracking as $t) {
            $yaml = $loaded[$t->getSampleKey()] ?? null;
            if (!is_array($yaml)) {
                continue;
            }
            $entity = $this->entityManager->find($t->getEntityClass(), $t->getEntityId());
            if ($entity === null) {
                continue;
            }
            // YAML hat eine Top-Level-Liste pro Entity-Typ. Such die
            // einzelne YAML-Row anhand des Natural-Keys.
            $shortName = (new \ReflectionClass($entity))->getShortName();
            $entityType = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $shortName)) . 's';
            $rows = $yaml[$entityType] ?? null;
            if (!is_array($rows)) {
                // Manche YAML-Files nutzen abweichende Plurale (people,
                // dpias, …). Iteriere alle Top-Level-Keys.
                foreach ($yaml as $candidate) {
                    if (is_array($candidate)) {
                        $rows = $candidate;
                        break;
                    }
                }
            }
            if (!is_array($rows)) {
                continue;
            }
            // Match by natural-key field (title/name/etc).
            $keys = $this->referenceNaturalKeys();
            $keyField = $keys[rtrim($entityType, 's')] ?? $keys[$entityType] ?? null;
            $entityKeyVal = null;
            if ($keyField !== null && method_exists($entity, 'get' . ucfirst($keyField))) {
                $entityKeyVal = $entity->{'get' . ucfirst($keyField)}();
            }
            $row = null;
            foreach ($rows as $r) {
                if (is_array($r) && isset($r[$keyField]) && $r[$keyField] === $entityKeyVal) {
                    $row = $r;
                    break;
                }
            }
            if ($row === null) {
                continue;
            }
            // Walk row; for each ref:- value, only set if entity-property
            // is currently null and ref now resolves.
            foreach ($row as $prop => $value) {
                if (!is_string($value) || !str_starts_with($value, 'ref:')) {
                    continue;
                }
                $getter = 'get' . ucfirst(lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $prop)))));
                $setter = 'set' . ucfirst(lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $prop)))));
                if (!method_exists($entity, $getter) || !method_exists($entity, $setter)) {
                    continue;
                }
                if ($entity->$getter() !== null) {
                    continue;
                }
                $resolved = $this->resolveReference($value);
                if ($resolved !== null) {
                    try {
                        $entity->$setter($resolved);
                        $repaired++;
                    } catch (\TypeError) {
                        // Setter-Type passt nicht — überspringen
                    }
                }
            }
        }

        if ($repaired > 0) {
            try {
                $this->entityManager->flush();
            } catch (Exception $e) {
                $this->addLog('Repair-flush failed: ' . $e->getMessage());
                return 0;
            }
        }
        return $repaired;
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

            // Reset Ref-Tracker pro Sample, sonst summieren sich Werte aus
            // vorigen Samples — User würde irreführend ref-Anzahlen sehen.
            $this->unresolvedRefs = [];

            // Importiere Entitäten basierend auf Datenstruktur
            $errors = [];
            $imported = $this->importEntities($data, $errors);

            $hasErrors = $errors !== [];
            $message = "{$imported} Datensätze importiert";
            if ($hasErrors) {
                $errorPreview = implode(' | ', array_slice($errors, 0, 3));
                $more = count($errors) > 3 ? sprintf(' (+%d weitere)', count($errors) - 3) : '';
                $message .= sprintf(' — %d Fehler: %s%s', count($errors), $errorPreview, $more);
            }

            // Unresolved-Refs surfacen — typischer Fall: User importiert
            // Risks ohne vorher Assets, oder DPIA ohne vorher
            // Verarbeitungstätigkeiten. Daten landen in DB, aber Beziehungen
            // fehlen. Hinweis im Flash erlaubt dem User die fehlenden
            // Samples nachzuladen, dann Re-Import (Merge füllt Refs nach).
            $unresolved = array_unique($this->unresolvedRefs);
            if ($unresolved !== []) {
                $previewRefs = array_slice($unresolved, 0, 3);
                $more = count($unresolved) > 3 ? sprintf(' (+%d weitere)', count($unresolved) - 3) : '';
                $message .= sprintf(
                    ' — %d Beziehungen nicht aufgelöst (zugehörige Beispiele zuerst importieren?): %s%s',
                    count($unresolved),
                    implode(' · ', $previewRefs),
                    $more,
                );
            }

            return [
                'success' => $imported > 0 && !$hasErrors,
                'message' => $message,
                'count' => $imported,
                'errors' => $errors,
                'unresolved_refs' => $unresolved,
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
    /**
     * @param-out list<string> $errors
     */
    private function importEntities(array $data, array &$errors = []): int
    {
        $count = 0;
        $ctx = $this->activeImportContext;
        $tenant = $ctx['tenant'] ?? $this->resolveImportTenant();
        $created = [];
        $errors = [];

        foreach ($data as $entityType => $entities) {
            $entityClass = $this->resolveEntityClass($entityType);

            if (!$entityClass) {
                $msg = "Unknown entity type: {$entityType}";
                $this->addLog($msg);
                $errors[] = $msg;
                continue;
            }

            $naturalKeyField = $this->referenceNaturalKeys()[$entityType]
                ?? $this->referenceNaturalKeys()[rtrim($entityType, 's')]
                ?? null;

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

                    // Idempotenz: Wenn ein Datensatz mit gleichem Natural-Key
                    // bereits existiert, überspringen statt Duplicate-Key-
                    // Constraint-Violation auszulösen. So kann der User den
                    // gleichen Sample mehrfach klicken ohne Fehler.
                    if ($naturalKeyField !== null) {
                        $criteria = [];
                        $getter = 'get' . ucfirst($naturalKeyField);
                        if (method_exists($entity, $getter)) {
                            $keyValue = $entity->$getter();
                            if ($keyValue !== null && $keyValue !== '') {
                                $criteria[$naturalKeyField] = $keyValue;
                                if ($tenant && method_exists($entity, 'getTenant')) {
                                    $criteria['tenant'] = $tenant;
                                }
                                $existing = $this->entityManager->getRepository($entityClass)->findOneBy($criteria);
                                if ($existing !== null) {
                                    // Bestehende Entity mit YAML-Daten mergen, damit
                                    // Re-Imports kaputte Felder (z. B. asset_id=NULL aus
                                    // einem früher fehlgeschlagenen Import) reparieren.
                                    // Ohne das würde die NEUE Entity verworfen und die
                                    // existierende, kaputte überleben.
                                    $this->createEntity($entityClass, $entityData, $existing);
                                    $this->addLog("Refreshed existing {$entityClass} where {$naturalKeyField}={$keyValue}");
                                    $created[] = $existing;
                                    $count++;
                                    continue;
                                }
                            }
                        }
                    }

                    $this->entityManager->persist($entity);
                    $created[] = $entity;
                    $count++;
                } catch (Exception $e) {
                    $hint = $entityClass . ': ' . $e->getMessage();
                    $this->addLog("Error creating entity {$entityClass}: " . $e->getMessage());
                    $errors[] = $hint;
                }
            }
        }

        try {
            $this->entityManager->flush();
        } catch (Exception $e) {
            $msg = 'Flush fehlgeschlagen: ' . $e->getMessage();
            $this->addLog($msg);
            $errors[] = $msg;
            // EntityManager schließt nach Constraint-Violation — neu öffnen
            // damit nachfolgende Tracking-Persists nicht ebenfalls scheitern.
            if (!$this->entityManager->isOpen()) {
                $this->addLog('EntityManager closed — bailing out for this import');
                return 0;
            }
        }

        // Tracking-Records nach dem Flush (damit Entity-IDs existieren).
        if ($ctx !== null && $count > 0) {
            // Tenant + User auf den AKTUELLEN EM neu binden — könnten durch
            // resetEntityManagerIfClosed() detached worden sein, und dann
            // beim setTenant/setImportedBy einen "new entity"-Cascade-Error
            // werfen.
            $trackTenant = $ctx['tenant'] ?? null;
            if ($trackTenant !== null && $trackTenant->getId() !== null) {
                $managedTenant = $this->entityManager->find(get_class($trackTenant), $trackTenant->getId());
                if ($managedTenant !== null) {
                    $trackTenant = $managedTenant;
                }
            }
            $trackUser = $ctx['importedBy'] ?? null;
            if ($trackUser !== null && method_exists($trackUser, 'getId') && $trackUser->getId() !== null) {
                $managedUser = $this->entityManager->find(get_class($trackUser), $trackUser->getId());
                if ($managedUser !== null) {
                    $trackUser = $managedUser;
                }
            }
            $sampleKeyStr = (string) $ctx['sampleKey'];
            // Existierende Tracking-Records dieses Samples laden, damit wir
            // sie nicht duplizieren (siehe Bug: 130 Rows für 10 Assets).
            $existingTracks = $this->sampleImportRepository->findBy([
                'sampleKey' => $sampleKeyStr,
                'tenant' => $trackTenant,
            ]);
            $trackedKeys = [];
            foreach ($existingTracks as $existingTrack) {
                $trackedKeys[$existingTrack->getEntityClass() . '#' . $existingTrack->getEntityId()] = true;
            }

            foreach ($created as $entity) {
                if (!method_exists($entity, 'getId') || $entity->getId() === null) {
                    continue;
                }
                $entityKey = $entity::class . '#' . $entity->getId();
                if (isset($trackedKeys[$entityKey])) {
                    continue; // Re-import: Tracking-Row existiert bereits
                }
                $track = new SampleDataImport();
                $track->setSampleKey($sampleKeyStr);
                $track->setEntityClass($entity::class);
                $track->setEntityId((int) $entity->getId());
                $track->setTenant($trackTenant);
                $track->setImportedBy($trackUser);
                $this->entityManager->persist($track);
                $trackedKeys[$entityKey] = true;
            }
            try {
                $this->entityManager->flush();
            } catch (Exception $e) {
                $errors[] = 'Tracking-Flush fehlgeschlagen: ' . $e->getMessage();
                $this->addLog('Tracking-Flush failed: ' . $e->getMessage());
            }
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
        // Falls EM von vorigem Sample-Remove geschlossen wurde — neu öffnen,
        // sonst cascadet jeder weitere Aufruf mit "EntityManager is closed".
        $this->resetEntityManagerIfClosed();
        // Tenant + Repository nach evtl. Reset auf den AKTUELLEN EM neu binden.
        if ($tenant->getId() !== null) {
            $managed = $this->entityManager->find(get_class($tenant), $tenant->getId());
            if ($managed instanceof Tenant) {
                $tenant = $managed;
            }
        }
        $trackingRepo = $this->entityManager->getRepository(SampleDataImport::class);
        $trackedImports = $trackingRepo->findBy(['sampleKey' => $sampleKey, 'tenant' => $tenant]);
        // Umgekehrt sortieren → FK-sicherer, neueste Inserts zuerst gelöscht.
        usort($trackedImports, fn(SampleDataImport $a, SampleDataImport $b) => $b->getId() <=> $a->getId());

        $removed = 0;
        $errors = [];

        // Pro Entity einzeln flushen, damit ein FK-Constraint-Violation auf
        // entity#N nicht alle übrigen mitreißt. Nach Flush-Fehler wird der EM
        // zurückgesetzt + Entity übersprungen, dann mit Entity#N+1 weiter.
        foreach ($trackedImports as $track) {
            try {
                $entity = $this->entityManager->find($track->getEntityClass(), $track->getEntityId());
                if ($entity !== null) {
                    $this->entityManager->remove($entity);
                }
                // Re-fetch des Tracking-Records auf dem aktuellen EM
                // (kann nach Reset detached worden sein).
                $managedTrack = $this->entityManager->find(SampleDataImport::class, $track->getId()) ?? $track;
                if ($this->entityManager->contains($managedTrack)) {
                    $this->entityManager->remove($managedTrack);
                }
                $this->entityManager->flush();
                if ($entity !== null) {
                    $removed++;
                }
            } catch (Exception $e) {
                $errors[] = sprintf('%s#%d: %s', $track->getEntityClass(), $track->getEntityId(), $e->getMessage());
                // Nach Flush-Fehler: EM zurücksetzen + Tenant neu binden, sonst
                // ist alles Folgende tot. Tracking-Record bleibt — User sieht
                // den Fehler und kann manuell nachfassen.
                $this->resetEntityManagerIfClosed();
                if ($tenant->getId() !== null) {
                    $managed = $this->entityManager->find(get_class($tenant), $tenant->getId());
                    if ($managed instanceof Tenant) {
                        $tenant = $managed;
                    }
                }
            }
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
            // Singular-Aliase für ref:-Lookups (irreguläre Plurale wie
            // activity→activities oder person→people brechen sonst den
            // Default-`+s`-Fallback in resolveReference).
            'asset' => Asset::class,
            'risk' => Risk::class,
            'control' => Control::class,
            'incident' => Incident::class,
            'business_process' => BusinessProcess::class,
            'training' => Training::class,
            'document' => Document::class,
            'management_review' => ManagementReview::class,
            'processing_activity' => ProcessingActivity::class,
            'data_breach' => DataBreach::class,
            'consent' => Consent::class,
            'dpia' => DataProtectionImpactAssessment::class,
            'data_subject_request' => DataSubjectRequest::class,
            'bc_plan' => BusinessContinuityPlan::class,
            'business_continuity_plan' => BusinessContinuityPlan::class,
            'bc_exercise' => BCExercise::class,
            'crisis_team' => CrisisTeam::class,
            'supplier' => Supplier::class,
            'location' => Location::class,
            'person' => Person::class,
            'interested_party' => InterestedParty::class,
            'objective' => ISMSObjective::class,
        ];

        return $mapping[$entityType] ?? null;
    }

    /**
     * Liest den Doctrine-Column-Type (z. B. 'date', 'datetime_immutable')
     * für ein Property. Liefert null wenn Property kein gemapptes Feld ist.
     */
    private function resolveDoctrineColumnType(string $entityClass, string $property): ?string
    {
        try {
            $metadata = $this->entityManager->getClassMetadata($entityClass);
        } catch (\Throwable) {
            return null;
        }
        // YAML kann snake_case verwenden, Entity-Property ist camelCase.
        $candidates = [
            $property,
            lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $property)))),
        ];
        foreach ($candidates as $field) {
            if (isset($metadata->fieldMappings[$field]['type'])) {
                return (string) $metadata->fieldMappings[$field]['type'];
            }
        }
        return null;
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
            $this->unresolvedRefs[] = $value;
        }
        return $entity;
    }

    /**
     * Erstellt Entity aus Array-Daten.
     * Unterstützt scalar values, Datum-Strings (Reflection auf Setter-Typ),
     * ref:-Strings (Entity-Lookup per Natural-Key) und Collections.
     */
    private function createEntity(string $entityClass, array $data, ?object $target = null): object
    {
        // Wenn $target gesetzt: bestehende Entity wird mit YAML-Werten
        // aktualisiert (Re-Import-Repair). Sonst: frische Entity.
        $entity = $target ?? new $entityClass();
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

            // Enum-Strings: wenn der Setter einen BackedEnum-Typ erwartet,
            // String-Wert über ::tryFrom() konvertieren. Sonst wirft PHP
            // TypeError, der vom outer try/catch geschluckt wird → Property
            // bleibt null → DB-NotNullConstraint-Violation beim flush().
            if (is_string($value)) {
                try {
                    $reflection = new \ReflectionMethod($entity, $setter);
                    $paramType = $reflection->getParameters()[0]?->getType();
                    $typeName = $paramType instanceof \ReflectionNamedType ? $paramType->getName() : null;
                    if ($typeName !== null && enum_exists($typeName)) {
                        $enumValue = $typeName::tryFrom($value);
                        if ($enumValue === null) {
                            $this->addLog("Skipped {$entityClass}::{$setter} — '{$value}' nicht in enum {$typeName}");
                            continue;
                        }
                        $value = $enumValue;
                    }
                } catch (\ReflectionException) {
                    // Kein Setter-Reflection — fallthrough
                }
            }

            // Datum-Strings: je nach Doctrine-Column-Type DateTimeImmutable
            // oder DateTime konstruieren. Setter-Reflection allein reicht
            // nicht (Property-Typ ist meist DateTimeInterface), Doctrine
            // unterscheidet aber zwischen *_MUTABLE und *_IMMUTABLE und wirft
            // sonst beim flush() Conversion-Errors.
            if (is_string($value) && strtotime($value) !== false) {
                $columnType = $this->resolveDoctrineColumnType($entity::class, (string) $property);
                $useImmutable = match (true) {
                    $columnType === null => true, // Konservativer Default
                    str_contains($columnType, '_immutable') => true,
                    in_array($columnType, ['date', 'datetime', 'datetimetz', 'time'], true) => false,
                    default => true,
                };
                try {
                    $value = $useImmutable ? new \DateTimeImmutable($value) : new \DateTime($value);
                } catch (\Exception) {
                    // String-Format unbrauchbar — setter wirft ggf.
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
