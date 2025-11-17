<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Service to write environment configuration to .env.local file.
 *
 * Security considerations:
 * - Only writes to .env.local (never .env)
 * - Validates variable names (alphanumeric + underscore only)
 * - Properly escapes values for shell safety
 * - Creates backup before overwriting
 */
class EnvironmentWriter
{
    private const ENV_LOCAL_FILE = '/.env.local';
    private const BACKUP_SUFFIX = '.backup';

    public function __construct(
        private readonly ParameterBagInterface $params
    ) {
    }

    /**
     * Write database configuration to .env.local
     *
     * @param array $config Configuration array with keys: type, host, port, name, user, password, unixSocket
     * @throws \RuntimeException if write fails
     */
    public function writeDatabaseConfig(array $config): void
    {
        $type = $config['type'] ?? 'mysql';
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? $this->getDefaultPort($type);
        $name = $config['name'] ?? 'little_isms_helper';
        $user = $config['user'] ?? 'root';
        $password = $config['password'] ?? '';
        $unixSocket = $config['unixSocket'] ?? null;

        // Prepare environment variables
        // Store individual components first, then reference them in DATABASE_URL
        $envVars = [];

        // If not SQLite, store individual components and use variable references in URL
        if ($type !== 'sqlite') {
            $envVars['DB_TYPE'] = $type;
            $envVars['DB_HOST'] = $host;
            $envVars['DB_PORT'] = (string)$port;
            $envVars['DB_NAME'] = $name;
            $envVars['DB_USER'] = $user;
            $envVars['DB_PASS'] = $password;
            if (!empty($unixSocket)) {
                $envVars['DB_SOCKET'] = $unixSocket;
            }

            // Build DATABASE_URL using variable references
            // This avoids URL-encoding issues with special characters in passwords
            $databaseUrl = match ($type) {
                'mysql', 'mariadb' => $this->buildMysqlDatabaseUrlWithVars($name, $config['serverVersion'] ?? '8.0', $unixSocket),
                'postgresql' => sprintf(
                    'postgresql://${DB_USER}:${DB_PASS}@${DB_HOST}:${DB_PORT}/%s?serverVersion=%s&charset=utf8',
                    $name,
                    $config['serverVersion'] ?? '14'
                ),
                default => throw new \InvalidArgumentException("Unsupported database type: {$type}")
            };
        } else {
            // SQLite doesn't need credentials
            $databaseUrl = sprintf(
                'sqlite:///%s/var/%s.db',
                '%%kernel.project_dir%%',  // %% escapes to single % in .env
                $name
            );
        }

        $envVars['DATABASE_URL'] = $databaseUrl;

        $this->writeEnvVariables($envVars);
    }

    /**
     * Build MySQL/MariaDB DATABASE_URL using environment variable references
     * This avoids URL-encoding issues with special characters in passwords
     */
    private function buildMysqlDatabaseUrlWithVars(string $name, string $serverVersion, ?string $unixSocket = null): string
    {
        // Use Unix socket if explicitly provided
        if (!empty($unixSocket)) {
            // Unix socket connection (better performance, no TCP overhead)
            return sprintf(
                'mysql://${DB_USER}:${DB_PASS}@localhost/%s?unix_socket=%s&serverVersion=%s&charset=utf8mb4',
                $name,
                $unixSocket,
                $serverVersion
            );
        }

        // Standard TCP connection using variable references
        return sprintf(
            'mysql://${DB_USER}:${DB_PASS}@${DB_HOST}:${DB_PORT}/%s?serverVersion=%s&charset=utf8mb4',
            $name,
            $serverVersion
        );
    }

    /**
     * Build MySQL/MariaDB DATABASE_URL with optional Unix socket support
     * @deprecated Use buildMysqlDatabaseUrlWithVars() instead to avoid special character issues
     */
    private function buildMysqlDatabaseUrl(string $host, int $port, string $user, string $password, string $name, string $serverVersion, ?string $unixSocket = null): string
    {
        // URL-encode user and password
        // The resulting URL will contain % which will trigger escapeEnvValue() to wrap it in quotes
        $encodedUser = urlencode($user);
        $encodedPassword = urlencode($password);

        // Use Unix socket if explicitly provided
        if (!empty($unixSocket)) {
            // Unix socket connection (better performance, no TCP overhead)
            return sprintf(
                'mysql://%s:%s@localhost/%s?unix_socket=%s&serverVersion=%s&charset=utf8mb4',
                $encodedUser,
                $encodedPassword,
                $name,
                $unixSocket,  // Raw path, not URL-encoded
                $serverVersion
            );
        }

        // Standard TCP connection
        return sprintf(
            'mysql://%s:%s@%s:%s/%s?serverVersion=%s&charset=utf8mb4',
            $encodedUser,
            $encodedPassword,
            $host,
            $port,
            $name,
            $serverVersion
        );
    }

    /**
     * Write arbitrary environment variables to .env.local
     *
     * @param array $variables Key-value pairs of environment variables
     * @throws \RuntimeException if write fails
     */
    public function writeEnvVariables(array $variables): void
    {
        $envFilePath = $this->getEnvLocalPath();

        // Validate variable names
        foreach (array_keys($variables) as $key) {
            if (!$this->isValidVariableName($key)) {
                throw new \InvalidArgumentException("Invalid environment variable name: {$key}");
            }
        }

        // Read existing .env.local if it exists
        $existingVars = $this->readEnvLocal();

        // Create backup if file exists
        if (file_exists($envFilePath)) {
            $this->createBackup();
        }

        // Merge with new variables (new values override)
        $mergedVars = array_merge($existingVars, $variables);

        // Build .env.local content
        $content = $this->buildEnvFileContent($mergedVars);

        // Atomic write: Write to temp file first, then rename
        // This prevents corruption if disk full or process killed
        $tmpFilePath = $envFilePath . '.tmp';

        try {
            // Write to temporary file
            $bytesWritten = file_put_contents($tmpFilePath, $content);

            if ($bytesWritten === false || $bytesWritten !== strlen($content)) {
                throw new \RuntimeException("Failed to write to temporary file {$tmpFilePath}");
            }

            // Set proper permissions before rename
            chmod($tmpFilePath, 0600);

            // Atomic rename (this is atomic on POSIX systems)
            if (!rename($tmpFilePath, $envFilePath)) {
                throw new \RuntimeException("Failed to rename temporary file to {$envFilePath}");
            }
        } catch (\Exception $e) {
            // Cleanup: Remove temp file if it exists
            if (file_exists($tmpFilePath)) {
                @unlink($tmpFilePath);
            }
            throw $e;
        }
    }

    /**
     * Generate APP_SECRET if not already set
     */
    public function ensureAppSecret(): void
    {
        $envFilePath = $this->getEnvLocalPath();
        $existingVars = $this->readEnvLocal();

        // Check if APP_SECRET already exists and is not empty
        if (!empty($existingVars['APP_SECRET'])) {
            return;
        }

        // Generate new secret
        $secret = bin2hex(random_bytes(32));

        $this->writeEnvVariables(['APP_SECRET' => $secret]);
    }

    /**
     * Read existing .env.local variables
     *
     * @return array Key-value pairs of environment variables
     */
    private function readEnvLocal(): array
    {
        $envFilePath = $this->getEnvLocalPath();

        if (!file_exists($envFilePath)) {
            return [];
        }

        $content = file_get_contents($envFilePath);
        if ($content === false) {
            return [];
        }

        $vars = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Parse KEY=VALUE
            if (preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/i', $line, $matches)) {
                $key = $matches[1];
                $value = $matches[2];

                // Remove quotes if present
                if (preg_match('/^["\'](.*)["\']\s*$/', $value, $valueMatches)) {
                    $value = $valueMatches[1];
                }

                $vars[$key] = $value;
            }
        }

        return $vars;
    }

    /**
     * Build .env.local file content from variables
     */
    private function buildEnvFileContent(array $variables): string
    {
        $content = "# =============================================================================\n";
        $content .= "# LOCAL ENVIRONMENT CONFIGURATION\n";
        $content .= "# =============================================================================\n";
        $content .= "# This file was generated by the Setup Wizard\n";
        $content .= "# Generated at: " . date('Y-m-d H:i:s') . "\n";
        $content .= "# =============================================================================\n\n";

        foreach ($variables as $key => $value) {
            // DATABASE_URL contains ${VAR} references - must NOT be quoted or escaped
            // Symfony's .env parser only expands variables outside of quotes
            if ($key === 'DATABASE_URL' && str_contains($value, '${')) {
                $content .= "{$key}={$value}\n";
            } else {
                // Escape special characters in value
                $escapedValue = $this->escapeEnvValue($value);
                $content .= "{$key}={$escapedValue}\n";
            }
        }

        return $content;
    }

    /**
     * Escape environment variable value for safe storage
     */
    private function escapeEnvValue(string $value): string
    {
        // IMPORTANT: Symfony's .env parser interprets % as parameter placeholder even in quotes
        // We must escape % as %% BEFORE any other escaping
        $value = str_replace('%', '%%', $value);

        // If value contains special characters, wrap in double quotes
        if (preg_match('/[\\s#$&*(){}[\]|;\'"`<>]/', $value)) {
            // Escape existing double quotes and backslashes
            $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
            return "\"{$value}\"";
        }

        return $value;
    }

    /**
     * Validate environment variable name
     */
    private function isValidVariableName(string $name): bool
    {
        return preg_match('/^[A-Z_][A-Z0-9_]*$/i', $name) === 1;
    }

    /**
     * Create backup of .env.local
     */
    private function createBackup(): void
    {
        $envFilePath = $this->getEnvLocalPath();
        $backupPath = $envFilePath . self::BACKUP_SUFFIX;

        copy($envFilePath, $backupPath);
    }

    /**
     * Get default port for database type
     */
    private function getDefaultPort(string $type): int
    {
        return match ($type) {
            'mysql', 'mariadb' => 3306,
            'postgresql' => 5432,
            default => 3306,
        };
    }

    /**
     * Get path to .env.local file
     */
    private function getEnvLocalPath(): string
    {
        return $this->params->get('kernel.project_dir') . self::ENV_LOCAL_FILE;
    }

    /**
     * Check if .env.local exists
     */
    public function envLocalExists(): bool
    {
        return file_exists($this->getEnvLocalPath());
    }

    /**
     * Check if we can write to .env.local
     *
     * @return array Result with 'writable' boolean and 'message' string
     */
    public function checkWritePermissions(): array
    {
        $envFilePath = $this->getEnvLocalPath();
        $projectDir = $this->params->get('kernel.project_dir');

        // Check if .env.local exists
        if (file_exists($envFilePath)) {
            // File exists - check if writable
            if (!is_writable($envFilePath)) {
                return [
                    'writable' => false,
                    'message' => "File {$envFilePath} exists but is not writable. Please check file permissions (should be 0600 or 0644).",
                ];
            }
        } else {
            // File doesn't exist - check if parent directory is writable
            if (!is_writable($projectDir)) {
                return [
                    'writable' => false,
                    'message' => "Project directory {$projectDir} is not writable. Cannot create .env.local file.",
                ];
            }
        }

        // Check var/ directory (needed for SQLite)
        $varDir = $projectDir . '/var';
        if (!is_dir($varDir)) {
            // Try to create it
            if (!@mkdir($varDir, 0755, true)) {
                return [
                    'writable' => false,
                    'message' => "Cannot create var/ directory. Please create it manually with: mkdir -p {$varDir} && chmod 755 {$varDir}",
                ];
            }
        }

        if (!is_writable($varDir)) {
            return [
                'writable' => false,
                'message' => "Directory {$varDir} is not writable. This is required for SQLite databases and file storage.",
            ];
        }

        return [
            'writable' => true,
            'message' => 'All filesystem permissions OK',
        ];
    }

    /**
     * Get current database configuration from environment
     *
     * @return array|null Configuration array or null if not configured
     */
    public function getCurrentDatabaseConfig(): ?array
    {
        $vars = $this->readEnvLocal();

        if (empty($vars['DATABASE_URL'])) {
            return null;
        }

        // Parse DATABASE_URL to extract components
        $url = $vars['DATABASE_URL'];

        if (preg_match('/^(\w+):\/\/([^:]+):([^@]+)@([^:]+):(\d+)\/([^?]+)/', $url, $matches)) {
            return [
                'type' => $matches[1] === 'postgresql' ? 'postgresql' : 'mysql',
                'user' => $matches[2],
                'password' => $matches[3],
                'host' => $matches[4],
                'port' => (int)$matches[5],
                'name' => $matches[6],
            ];
        }

        if (str_starts_with($url, 'sqlite://')) {
            return [
                'type' => 'sqlite',
                'name' => basename($url, '.db'),
            ];
        }

        return null;
    }
}
