<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form for database configuration during setup wizard.
 */
class DatabaseConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Build available DB types based on loaded PHP extensions
        $availableTypes = [];
        $defaultType = null;

        // Check for MySQL/MariaDB support
        if (extension_loaded('pdo_mysql')) {
            $availableTypes['MySQL'] = 'mysql';
            $availableTypes['MariaDB'] = 'mariadb';
            if ($defaultType === null) {
                $defaultType = 'mysql';
            }
        }

        // Check for PostgreSQL support
        if (extension_loaded('pdo_pgsql')) {
            $availableTypes['PostgreSQL'] = 'postgresql';
            if ($defaultType === null) {
                $defaultType = 'postgresql';
            }
        }

        // Check for SQLite support (usually available)
        if (extension_loaded('pdo_sqlite')) {
            $availableTypes['SQLite'] = 'sqlite';
            $defaultType = $defaultType ?? 'sqlite';
        }

        // If no PDO extensions available, show error
        if (empty($availableTypes)) {
            throw new \RuntimeException(
                'No PDO database extensions found. Please install at least one of: pdo_mysql, pdo_pgsql, or pdo_sqlite'
            );
        }

        // Get data passed from controller (if any)
        $formData = $options['data'] ?? [];

        // Parse existing .env.local DATABASE_URL if available (fallback only)
        $existingConfig = $this->parseExistingDatabaseUrl();

        // Detect if running in standalone Docker container (local MariaDB)
        $isStandaloneDocker = $this->detectStandaloneDocker();

        // Determine default values: Controller data takes priority, then existing config, then Docker detection
        // IMPORTANT: Only use fallback defaults if controller didn't provide data
        $defaultDbType = $formData['type'] ?? $existingConfig['type'] ?? ($isStandaloneDocker ? 'mariadb' : $defaultType);
        $defaultHost = $formData['host'] ?? $existingConfig['host'] ?? 'localhost';
        $defaultPort = $formData['port'] ?? $existingConfig['port'] ?? null;
        $defaultName = $formData['name'] ?? $existingConfig['name'] ?? ($isStandaloneDocker ? 'isms' : 'little_isms_helper');
        $defaultUser = $formData['user'] ?? $existingConfig['user'] ?? ($isStandaloneDocker ? 'isms' : '');
        $defaultPassword = $formData['password'] ?? $existingConfig['password'] ?? '';
        $defaultSocket = $formData['unixSocket'] ?? $existingConfig['socket'] ?? $this->detectUnixSocket();
        $defaultVersion = $formData['serverVersion'] ?? $existingConfig['serverVersion'] ?? ($isStandaloneDocker ? 'mariadb-11.4.0' : '');

        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'setup.database.type',
                'choices' => $availableTypes,
                'data' => $defaultDbType,
                'required' => true,
                'attr' => [
                    'class' => 'form-select',
                    'data-controller' => 'database-type',
                    'data-action' => 'change->database-type#toggle',
                ],
                'help' => 'setup.database.type_help',
                    'choice_translation_domain' => 'admin',
            ])
            ->add('host', TextType::class, [
                'label' => 'setup.database.host',
                'data' => $defaultHost,
                'required' => false,
                'constraints' => [
                    new Assert\When(
                        expression: 'this.getParent()["type"].getData() !== "sqlite"',
                        constraints: [new Assert\NotBlank()],
                    ),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'localhost',
                    'data-database-type-target' => 'hostField',
                ],
                'help' => 'setup.database.host_help',
            ])
            ->add('port', IntegerType::class, [
                'label' => 'setup.database.port',
                'data' => $defaultPort,
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '3306',
                    'data-database-type-target' => 'portField',
                ],
                'help' => 'setup.database.port_help',
            ])
            ->add('unixSocket', TextType::class, [
                'label' => 'setup.database.unix_socket',
                'data' => $defaultSocket,
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '/var/run/mysqld/mysqld.sock',
                    'data-database-type-target' => 'socketField',
                ],
                'help' => 'setup.database.unix_socket_help',
            ])
            ->add('name', TextType::class, [
                'label' => 'setup.database.name',
                'data' => $defaultName,
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex(
                        pattern: '/^[a-zA-Z0-9_]+$/',
                        message: 'setup.database.name_invalid'
                    ),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => $isStandaloneDocker ? 'isms' : 'little_isms_helper',
                ],
                'help' => 'setup.database.name_help',
            ])
            ->add('user', TextType::class, [
                'label' => 'setup.database.user',
                'data' => $defaultUser,
                'required' => false,
                'constraints' => [
                    new Assert\When(
                        expression: 'this.getParent()["type"].getData() !== "sqlite"',
                        constraints: [new Assert\NotBlank()],
                    ),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => $isStandaloneDocker ? 'isms' : 'root',
                    'data-database-type-target' => 'userField',
                ],
                'help' => 'setup.database.user_help',
            ])
            ->add('password', PasswordType::class, [
                'label' => 'setup.database.password',
                'data' => $defaultPassword,
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '••••••••',
                    'data-database-type-target' => 'passwordField',
                ],
                'help' => $isStandaloneDocker
                    ? 'setup.database.password_help_standalone'
                    : 'setup.database.password_help',
            ])
            ->add('serverVersion', TextType::class, [
                'label' => 'setup.database.server_version',
                'data' => $defaultVersion,
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => $isStandaloneDocker ? 'mariadb-11.4.0' : '8.0',
                    'data-database-type-target' => 'versionField',
                ],
                'help' => 'setup.database.server_version_help',
            'translation_domain' => 'admin',
            ]);

        // Add event listener to set default values based on database type
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $type = $data['type'] ?? 'mysql';

            // Set default port if not provided
            if (empty($data['port'])) {
                $data['port'] = match ($type) {
                    'postgresql' => 5432,
                    'mysql', 'mariadb' => 3306,
                    default => null,
                };
            }

            // Set default server version if not provided
            if (empty($data['serverVersion'])) {
                $data['serverVersion'] = match ($type) {
                    'postgresql' => '14',
                    'mariadb' => '10.6',
                    'mysql' => '8.0',
                    default => null,
                };
            }

            // Set default user if not provided (not for SQLite)
            if ($type !== 'sqlite' && empty($data['user'])) {
                $data['user'] = match ($type) {
                    'postgresql' => 'postgres',
                    default => 'root',
                };
            }

            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'database_config',
            'translation_domain' => 'messages',
        ]);
    }

    /**
     * Detect if running in standalone Docker container with embedded MariaDB.
     *
     * This checks for Docker-specific environment indicators without using file_exists
     * on system paths that might be restricted by open_basedir.
     */
    private function detectStandaloneDocker(): bool
    {
        // Check if DATABASE_URL contains the local socket configuration (safe check)
        $dbUrl = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? '';
        $usesLocalSocket = str_contains($dbUrl, 'unix_socket=/run/mysqld');

        // Check for Docker-specific environment variables
        $isDocker = isset($_ENV['DOCKER_CONTAINER']) || isset($_SERVER['DOCKER_CONTAINER']);

        // If using local socket in DATABASE_URL, assume Docker standalone
        if ($usesLocalSocket) {
            return true;
        }

        // Check if .dockerenv exists (only within project directory to avoid open_basedir issues)
        // This is a secondary check if no DATABASE_URL socket configuration is found
        if ($isDocker) {
            return true;
        }

        return false;
    }

    /**
     * Parse existing .env.local DATABASE_URL to pre-fill form fields.
     *
     * @return array<string, mixed> Parsed database configuration
     */
    private function parseExistingDatabaseUrl(): array
    {
        $config = [];

        // Try to read .env.local file (within project directory, so open_basedir safe)
        $envLocalPath = dirname(__DIR__, 2) . '/.env.local';
        if (!file_exists($envLocalPath)) {
            return $config;
        }

        $content = file_get_contents($envLocalPath);
        if ($content === false) {
            return $config;
        }

        // Extract DATABASE_URL line
        if (preg_match('/^DATABASE_URL=["\'"]?(.+?)["\'"]?\s*$/m', $content, $matches)) {
            $databaseUrl = trim($matches[1], '"\'');

            // Don't parse if it contains variables like ${...}
            if (str_contains($databaseUrl, '${') || str_contains($databaseUrl, '$')) {
                return $config;
            }

            // Parse the URL
            $parsed = parse_url($databaseUrl);
            if ($parsed === false) {
                return $config;
            }

            // Extract database type from scheme
            $scheme = $parsed['scheme'] ?? '';
            $config['type'] = match ($scheme) {
                'mysql' => 'mysql',
                'mysql2' => 'mysql',
                'pdo-mysql' => 'mysql',
                'pgsql' => 'postgresql',
                'postgresql' => 'postgresql',
                'postgres' => 'postgresql',
                'sqlite' => 'sqlite',
                default => null,
            };

            // If it's a MariaDB version string in query params, set type to mariadb
            if (isset($parsed['query'])) {
                parse_str($parsed['query'], $queryParams);
                if (isset($queryParams['serverVersion']) && str_contains($queryParams['serverVersion'], 'mariadb')) {
                    $config['type'] = 'mariadb';
                }
                $config['serverVersion'] = $queryParams['serverVersion'] ?? null;

                // Extract unix_socket if present
                if (isset($queryParams['unix_socket'])) {
                    $config['socket'] = $queryParams['unix_socket'];
                }
            }

            // Extract connection details
            $config['host'] = $parsed['host'] ?? 'localhost';
            $config['port'] = $parsed['port'] ?? null;
            $config['user'] = isset($parsed['user']) ? urldecode($parsed['user']) : null;
            // Don't auto-fill password for security reasons
            $config['name'] = isset($parsed['path']) ? ltrim($parsed['path'], '/') : null;
        }

        return array_filter($config, fn($v) => $v !== null);
    }

    /**
     * Detect common Unix socket paths for MySQL/MariaDB.
     *
     * Only checks paths within the project directory or common locations
     * that should be accessible.
     */
    private function detectUnixSocket(): ?string
    {
        // Common socket paths - we'll suggest the most likely one based on OS
        // but won't actually check if they exist to avoid open_basedir issues
        $commonPaths = [
            '/var/run/mysqld/mysqld.sock',      // Debian/Ubuntu
            '/var/lib/mysql/mysql.sock',        // RHEL/CentOS
            '/tmp/mysql.sock',                  // macOS/FreeBSD
            '/run/mysqld/mysqld.sock',          // Alpine/Docker
        ];

        // Check if any socket path is already configured in DATABASE_URL
        $dbUrl = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? '';
        if (preg_match('/unix_socket=([^&]+)/', $dbUrl, $matches)) {
            return $matches[1];
        }

        // Return null - let user specify if needed
        // We don't check file_exists to avoid open_basedir warnings
        return null;
    }
}
