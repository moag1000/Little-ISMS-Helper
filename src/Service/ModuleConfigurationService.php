<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

class ModuleConfigurationService
{
    private array $modules;
    private array $baseData;
    private array $sampleData;
    private string $configFile;

    public function __construct(
        private readonly string $projectDir
    ) {
        $this->configFile = $this->projectDir . '/config/modules.yaml';
        $this->loadConfiguration();
    }

    /**
     * Lädt Modul-Konfiguration
     */
    private function loadConfiguration(): void
    {
        $config = Yaml::parseFile($this->configFile);

        $this->modules = $config['modules'] ?? [];
        $this->baseData = $config['base_data'] ?? [];
        $this->sampleData = $config['sample_data'] ?? [];
    }

    /**
     * Gibt alle verfügbaren Module zurück
     */
    public function getAllModules(): array
    {
        return $this->modules;
    }

    /**
     * Gibt ein spezifisches Modul zurück
     */
    public function getModule(string $moduleKey): ?array
    {
        return $this->modules[$moduleKey] ?? null;
    }

    /**
     * Gibt erforderliche Module zurück
     */
    public function getRequiredModules(): array
    {
        return array_filter($this->modules, fn($module) => $module['required'] ?? false);
    }

    /**
     * Gibt optionale Module zurück
     */
    public function getOptionalModules(): array
    {
        return array_filter($this->modules, fn($module) => !($module['required'] ?? false));
    }

    /**
     * Validiert Modul-Auswahl (prüft Abhängigkeiten)
     */
    public function validateModuleSelection(array $selectedModules): array
    {
        $errors = [];
        $warnings = [];

        // Füge erforderliche Module automatisch hinzu
        foreach ($this->getRequiredModules() as $key => $module) {
            if (!in_array($key, $selectedModules)) {
                $selectedModules[] = $key;
                $warnings[] = "Modul '{$module['name']}' wurde automatisch hinzugefügt (erforderlich)";
            }
        }

        // Prüfe Abhängigkeiten
        foreach ($selectedModules as $moduleKey) {
            $module = $this->getModule($moduleKey);

            if (!$module) {
                $errors[] = "Modul '{$moduleKey}' nicht gefunden";
                continue;
            }

            foreach ($module['dependencies'] ?? [] as $dependency) {
                if (!in_array($dependency, $selectedModules)) {
                    $dependencyModule = $this->getModule($dependency);
                    $errors[] = "Modul '{$module['name']}' benötigt '{$dependencyModule['name']}'";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'modules' => array_unique($selectedModules),
        ];
    }

    /**
     * Berechnet Abhängigkeiten und fügt fehlende Module hinzu
     */
    public function resolveModuleDependencies(array $selectedModules): array
    {
        $resolved = $selectedModules;
        $added = [];

        // Füge erforderliche Module hinzu
        foreach ($this->getRequiredModules() as $key => $module) {
            if (!in_array($key, $resolved)) {
                $resolved[] = $key;
                $added[] = $key;
            }
        }

        $maxIterations = 10; // Verhindere Endlosschleife
        $iteration = 0;

        do {
            $changed = false;
            $iteration++;

            foreach ($resolved as $moduleKey) {
                $module = $this->getModule($moduleKey);

                foreach ($module['dependencies'] ?? [] as $dependency) {
                    if (!in_array($dependency, $resolved)) {
                        $resolved[] = $dependency;
                        $added[] = $dependency;
                        $changed = true;
                    }
                }
            }
        } while ($changed && $iteration < $maxIterations);

        return [
            'modules' => array_unique($resolved),
            'added' => array_unique($added),
        ];
    }

    /**
     * Speichert aktive Module in Konfigurationsdatei
     */
    public function saveActiveModules(array $modules): void
    {
        $configPath = $this->projectDir . '/config/active_modules.yaml';

        $data = [
            'active_modules' => $modules,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        file_put_contents($configPath, Yaml::dump($data, 4));
    }

    /**
     * Lädt aktive Module
     */
    public function getActiveModules(): array
    {
        $configPath = $this->projectDir . '/config/active_modules.yaml';

        if (!file_exists($configPath)) {
            // Standard: nur Core-Module
            return array_keys($this->getRequiredModules());
        }

        $config = Yaml::parseFile($configPath);
        return $config['active_modules'] ?? [];
    }

    /**
     * Prüft ob ein Modul aktiv ist
     */
    public function isModuleActive(string $moduleKey): bool
    {
        return in_array($moduleKey, $this->getActiveModules());
    }

    /**
     * Aktiviert ein Modul (mit Abhängigkeiten)
     */
    public function activateModule(string $moduleKey): array
    {
        $module = $this->getModule($moduleKey);

        if (!$module) {
            return [
                'success' => false,
                'error' => "Modul '{$moduleKey}' nicht gefunden",
            ];
        }

        $activeModules = $this->getActiveModules();

        if (in_array($moduleKey, $activeModules)) {
            return [
                'success' => true,
                'message' => "Modul '{$module['name']}' ist bereits aktiv",
                'already_active' => true,
            ];
        }

        // Füge Modul und Abhängigkeiten hinzu
        $activeModules[] = $moduleKey;
        $resolved = $this->resolveModuleDependencies($activeModules);

        $this->saveActiveModules($resolved['modules']);

        return [
            'success' => true,
            'message' => "Modul '{$module['name']}' wurde aktiviert",
            'added_modules' => $resolved['added'],
        ];
    }

    /**
     * Deaktiviert ein Modul
     */
    public function deactivateModule(string $moduleKey): array
    {
        $module = $this->getModule($moduleKey);

        if (!$module) {
            return [
                'success' => false,
                'error' => "Modul '{$moduleKey}' nicht gefunden",
            ];
        }

        if ($module['required'] ?? false) {
            return [
                'success' => false,
                'error' => "Modul '{$module['name']}' ist erforderlich und kann nicht deaktiviert werden",
            ];
        }

        $activeModules = $this->getActiveModules();

        if (!in_array($moduleKey, $activeModules)) {
            return [
                'success' => true,
                'message' => "Modul '{$module['name']}' ist bereits deaktiviert",
                'already_inactive' => true,
            ];
        }

        // Prüfe ob andere Module von diesem abhängen
        $dependents = [];
        foreach ($activeModules as $activeKey) {
            $activeModule = $this->getModule($activeKey);
            if (in_array($moduleKey, $activeModule['dependencies'] ?? [])) {
                $dependents[] = $activeModule['name'];
            }
        }

        if (!empty($dependents)) {
            return [
                'success' => false,
                'error' => "Modul '{$module['name']}' kann nicht deaktiviert werden. Folgende Module sind davon abhängig: " . implode(', ', $dependents),
                'dependents' => $dependents,
            ];
        }

        // Entferne Modul
        $activeModules = array_diff($activeModules, [$moduleKey]);
        $this->saveActiveModules($activeModules);

        return [
            'success' => true,
            'message' => "Modul '{$module['name']}' wurde deaktiviert",
        ];
    }

    /**
     * Gibt Basis-Daten zurück
     */
    public function getBaseData(): array
    {
        return $this->baseData;
    }

    /**
     * Gibt Beispiel-Daten zurück
     */
    public function getSampleData(): array
    {
        return $this->sampleData;
    }

    /**
     * Gibt Beispiel-Daten gefiltert nach aktiven Modulen zurück
     */
    public function getAvailableSampleData(array $activeModules = null): array
    {
        if ($activeModules === null) {
            $activeModules = $this->getActiveModules();
        }

        return array_filter($this->sampleData, function ($data) use ($activeModules) {
            $requiredModules = $data['required_modules'] ?? [];

            // Prüfe ob alle erforderlichen Module aktiv sind
            foreach ($requiredModules as $requiredModule) {
                if (!in_array($requiredModule, $activeModules)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Gibt Statistiken zurück
     */
    public function getStatistics(): array
    {
        $activeModules = $this->getActiveModules();
        $allModules = $this->modules;

        return [
            'total_modules' => count($allModules),
            'active_modules' => count($activeModules),
            'inactive_modules' => count($allModules) - count($activeModules),
            'required_modules' => count($this->getRequiredModules()),
            'optional_modules' => count($this->getOptionalModules()),
        ];
    }

    /**
     * Erstellt einen Dependency-Graph
     */
    public function getDependencyGraph(): array
    {
        $graph = [];

        foreach ($this->modules as $key => $module) {
            $graph[$key] = [
                'name' => $module['name'],
                'dependencies' => $module['dependencies'] ?? [],
                'dependents' => [],
                'required' => $module['required'] ?? false,
            ];
        }

        // Berechne Dependents (umgekehrte Abhängigkeiten)
        foreach ($this->modules as $key => $module) {
            foreach ($module['dependencies'] ?? [] as $dependency) {
                if (isset($graph[$dependency])) {
                    $graph[$dependency]['dependents'][] = $key;
                }
            }
        }

        return $graph;
    }
}
