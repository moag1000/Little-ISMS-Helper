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
     * @param array $config Configuration array with keys: type, host, port, name, user, password
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

        // Build DATABASE_URL based on type
        // Important: URL-encode user and password to handle special characters
        $databaseUrl = match ($type) {
            'mysql', 'mariadb' => $this->buildMysqlDatabaseUrl($host, $port, $user, $password, $name, $config['serverVersion'] ?? '8.0'),
            'postgresql' => sprintf(
                'postgresql://%s:%s@%s:%s/%s?serverVersion=%s&charset=utf8',
                urlencode($user),
                urlencode($password),
                $host,
                $port,
                $name,
                $config['serverVersion'] ?? '14'
            ),
            'sqlite' => sprintf(
                'sqlite:///%s/var/%s.db',
                '%kernel.project_dir%',
                $name
            ),
            default => throw new \InvalidArgumentException("Unsupported database type: {$type}")
        };

        // Prepare environment variables
        $envVars = [
            'DATABASE_URL' => $databaseUrl,
        ];

        // If not SQLite, also store individual components for easier access
        if ($type !== 'sqlite') {
            $envVars['DB_TYPE'] = $type;
            $envVars['DB_HOST'] = $host;
            $envVars['DB_PORT'] = $port;
            $envVars['DB_NAME'] = $name;
            $envVars['DB_USER'] = $user;
            $envVars['DB_PASS'] = $password;
        }

        $this->writeEnvVariables($envVars);
    }

    /**
     * Build MySQL/MariaDB DATABASE_URL with optional Unix socket support
     */
    private function buildMysqlDatabaseUrl(string $host, int $port, string $user, string $password, string $name, string $serverVersion): string
    {
        // Check if we should use Unix socket for localhost connections in Docker
        $useUnixSocket = false;
        $unixSocketPath = '/run/mysqld/mysqld.sock';

        // Use Unix socket if:
        // 1. Host is localhost AND
        // 2. Unix socket file exists (we're in standalone Docker container)
        if ($host === 'localhost' && file_exists($unixSocketPath)) {
            $useUnixSocket = true;
        }

        if ($useUnixSocket) {
            // Unix socket connection (better performance, no TCP overhead)
            return sprintf(
                'mysql://%s:%s@localhost/%s?unix_socket=%s&serverVersion=%s&charset=utf8mb4',
                urlencode($user),
                urlencode($password),
                $name,
                urlencode($unixSocketPath),
                $serverVersion
            );
        }

        // Standard TCP connection
        return sprintf(
            'mysql://%s:%s@%s:%s/%s?serverVersion=%s&charset=utf8mb4',
            urlencode($user),
            urlencode($password),
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
            // Escape special characters in value
            $escapedValue = $this->escapeEnvValue($value);
            $content .= "{$key}={$escapedValue}\n";
        }

        return $content;
    }

    /**
     * Escape environment variable value for safe storage
     */
    private function escapeEnvValue(string $value): string
    {
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
