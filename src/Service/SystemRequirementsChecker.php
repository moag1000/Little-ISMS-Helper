<?php

namespace App\Service;

use Exception;
use Symfony\Component\Yaml\Yaml;

class SystemRequirementsChecker
{
    private array $requirements;
    private array $results = [];

    public function __construct(
        private readonly string $projectDir
    ) {
        $configPath = $this->projectDir . '/config/modules.yaml';
        $config = Yaml::parseFile($configPath);
        $this->requirements = $config['requirements'] ?? [];
    }

    /**
     * Führt alle System-Checks durch
     */
    public function checkAll(): array
    {
        $this->results = [
            'php' => $this->checkPhpVersion(),
            'extensions' => $this->checkPhpExtensions(),
            'database' => $this->checkDatabaseConnection(),
            'permissions' => $this->checkDirectoryPermissions(),
            'memory' => $this->checkMemoryLimit(),
            'execution_time' => $this->checkExecutionTime(),
            'symfony' => $this->checkSymfonyVersion(),
        ];

        $this->results['overall'] = $this->calculateOverallStatus();

        return $this->results;
    }

    /**
     * Überprüft PHP-Version
     */
    private function checkPhpVersion(): array
    {
        $currentVersion = PHP_VERSION;
        $requiredVersion = $this->requirements['php']['version'] ?? '8.2.0';
        $passed = version_compare($currentVersion, $requiredVersion, '>=');

        return [
            'status' => $passed ? 'success' : 'error',
            'message' => $passed
                ? "PHP {$currentVersion} erfüllt Mindestanforderung {$requiredVersion}"
                : "PHP {$currentVersion} ist älter als erforderlich {$requiredVersion}",
            'current' => $currentVersion,
            'required' => $requiredVersion,
            'critical' => true,
        ];
    }

    /**
     * Überprüft PHP-Extensions
     */
    private function checkPhpExtensions(): array
    {
        $requiredExtensions = $this->requirements['php']['extensions'] ?? [];
        $missingExtensions = [];
        $loadedExtensions = [];

        foreach ($requiredExtensions as $requiredExtension) {
            // Special handling for OPcache which is a Zend extension
            if ($requiredExtension === 'opcache' || $requiredExtension === 'Zend OPcache') {
                if (extension_loaded('Zend OPcache') || function_exists('opcache_get_status')) {
                    $loadedExtensions[] = $requiredExtension;
                } else {
                    $missingExtensions[] = $requiredExtension;
                }
            } elseif (extension_loaded($requiredExtension)) {
                $loadedExtensions[] = $requiredExtension;
            } else {
                $missingExtensions[] = $requiredExtension;
            }
        }

        $allLoaded = $missingExtensions === [];

        return [
            'status' => $allLoaded ? 'success' : 'error',
            'message' => $allLoaded
                ? sprintf('Alle %d erforderlichen Extensions sind geladen', count($requiredExtensions))
                : sprintf('%d von %d Extensions fehlen: %s',
                    count($missingExtensions),
                    count($requiredExtensions),
                    implode(', ', $missingExtensions)
                ),
            'loaded' => $loadedExtensions,
            'missing' => $missingExtensions,
            'critical' => true,
        ];
    }

    /**
     * Überprüft Datenbankverbindung
     */
    private function checkDatabaseConnection(): array
    {
        try {
            // Prüfe ob DATABASE_URL in .env gesetzt ist
            $databaseUrl = $_ENV['DATABASE_URL'] ?? null;

            if (!$databaseUrl) {
                return [
                    'status' => 'warning',
                    'message' => 'DATABASE_URL nicht konfiguriert. Bitte .env Datei anpassen.',
                    'critical' => true,
                ];
            }

            // Parse Database URL
            $parsed = parse_url((string) $databaseUrl);
            $scheme = $parsed['scheme'] ?? 'unknown';

            return [
                'status' => 'success',
                'message' => sprintf('Datenbank konfiguriert: %s', $scheme),
                'type' => $scheme,
                'critical' => true,
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Fehler bei Datenbankprüfung: ' . $e->getMessage(),
                'critical' => true,
            ];
        }
    }

    /**
     * Überprüft Verzeichnis-Schreibrechte
     */
    private function checkDirectoryPermissions(): array
    {
        $requiredDirs = $this->requirements['permissions']['writable_directories'] ?? [];
        $notWritable = [];
        $writable = [];

        foreach ($requiredDirs as $requiredDir) {
            $fullPath = $this->projectDir . '/' . $requiredDir;

            // Erstelle Verzeichnis wenn nicht vorhanden
            if (!is_dir($fullPath)) {
                @mkdir($fullPath, 0755, true);
            }

            if (is_writable($fullPath)) {
                $writable[] = $requiredDir;
            } else {
                $notWritable[] = $requiredDir;
            }
        }

        $allWritable = $notWritable === [];

        return [
            'status' => $allWritable ? 'success' : 'error',
            'message' => $allWritable
                ? sprintf('Alle %d Verzeichnisse sind beschreibbar', count($requiredDirs))
                : sprintf('%d von %d Verzeichnissen sind nicht beschreibbar: %s',
                    count($notWritable),
                    count($requiredDirs),
                    implode(', ', $notWritable)
                ),
            'writable' => $writable,
            'not_writable' => $notWritable,
            'critical' => true,
        ];
    }

    /**
     * Überprüft Memory Limit
     */
    private function checkMemoryLimit(): array
    {
        $currentLimit = ini_get('memory_limit');
        $requiredLimit = $this->requirements['memory_limit'] ?? '256M';

        $currentBytes = $this->convertToBytes($currentLimit);
        $requiredBytes = $this->convertToBytes($requiredLimit);

        $passed = $currentLimit === '-1' || $currentBytes >= $requiredBytes;

        return [
            'status' => $passed ? 'success' : 'warning',
            'message' => $passed
                ? "Memory Limit {$currentLimit} ist ausreichend"
                : "Memory Limit {$currentLimit} ist niedriger als empfohlen {$requiredLimit}",
            'current' => $currentLimit,
            'required' => $requiredLimit,
            'critical' => false,
        ];
    }

    /**
     * Überprüft Max Execution Time
     */
    private function checkExecutionTime(): array
    {
        $currentTime = (int) ini_get('max_execution_time');
        $requiredTime = $this->requirements['max_execution_time'] ?? 300;

        $passed = $currentTime === 0 || $currentTime >= $requiredTime;

        return [
            'status' => $passed ? 'success' : 'warning',
            'message' => $passed
                ? "Max Execution Time {$currentTime}s ist ausreichend"
                : "Max Execution Time {$currentTime}s ist niedriger als empfohlen {$requiredTime}s",
            'current' => $currentTime,
            'required' => $requiredTime,
            'critical' => false,
        ];
    }

    /**
     * Überprüft Symfony Version
     */
    private function checkSymfonyVersion(): array
    {
        $composerLock = $this->projectDir . '/composer.lock';

        if (!file_exists($composerLock)) {
            return [
                'status' => 'warning',
                'message' => 'composer.lock nicht gefunden. Bitte "composer install" ausführen.',
                'critical' => true,
            ];
        }

        $lockData = json_decode(file_get_contents($composerLock), true);
        $symfonyVersion = null;

        foreach ($lockData['packages'] ?? [] as $package) {
            if ($package['name'] === 'symfony/framework-bundle') {
                $symfonyVersion = $package['version'];
                break;
            }
        }

        if (!$symfonyVersion) {
            return [
                'status' => 'error',
                'message' => 'Symfony Framework Bundle nicht gefunden',
                'critical' => true,
            ];
        }

        // Normalize version: remove 'v' prefix and stability suffixes for comparison
        $normalizedVersion = $this->normalizeVersion($symfonyVersion);
        $requiredVersion = $this->requirements['symfony']['version'] ?? '7.3.0';
        $passed = version_compare($normalizedVersion, $requiredVersion, '>=');

        return [
            'status' => $passed ? 'success' : 'error',
            'message' => $passed
                ? "Symfony {$symfonyVersion} erfüllt Mindestanforderung"
                : "Symfony {$symfonyVersion} ist älter als erforderlich {$requiredVersion}",
            'current' => $symfonyVersion,
            'required' => $requiredVersion,
            'critical' => true,
        ];
    }

    /**
     * Normalizes version string for comparison
     * Removes 'v' prefix and converts stability suffixes to version_compare format
     */
    private function normalizeVersion(string $version): string
    {
        // Remove 'v' prefix
        $version = ltrim($version, 'vV');

        // Convert stability suffixes to version_compare compatible format
        // RC, alpha, beta are understood by version_compare
        // But they need to be lowercase and without dash
        $version = preg_replace('/-?(RC|alpha|beta|dev|patch|pl|p)(\d*)/i', '$1$2', $version);

        return $version;
    }

    /**
     * Berechnet Gesamt-Status
     */
    private function calculateOverallStatus(): array
    {
        $criticalErrors = 0;
        $warnings = 0;
        $success = 0;

        foreach ($this->results as $key => $result) {
            if ($key === 'overall') {
                continue;
            }

            if ($result['status'] === 'error' && ($result['critical'] ?? false)) {
                $criticalErrors++;
            } elseif ($result['status'] === 'warning') {
                $warnings++;
            } elseif ($result['status'] === 'success') {
                $success++;
            }
        }

        $canProceed = $criticalErrors === 0;

        return [
            'can_proceed' => $canProceed,
            'critical_errors' => $criticalErrors,
            'warnings' => $warnings,
            'success' => $success,
            'total_checks' => count($this->results) - 1,
            'message' => $canProceed
                ? 'System erfüllt alle kritischen Anforderungen'
                : "System erfüllt {$criticalErrors} kritische Anforderungen nicht",
        ];
    }

    /**
     * Konvertiert Memory-Strings zu Bytes
     */
    private function convertToBytes(string $value): int
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Gibt die Ergebnisse zurück
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Prüft ob System bereit ist
     */
    public function isSystemReady(): bool
    {
        if ($this->results === []) {
            $this->checkAll();
        }

        return $this->results['overall']['can_proceed'] ?? false;
    }
}
