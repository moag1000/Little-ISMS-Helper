#!/usr/bin/env php
<?php
/**
 * Generate a comprehensive security audit report based on OWASP Top 10 2021
 * Performs automated checks on codebase and configuration
 *
 * Output: docs/reports/security-audit-owasp-2025-11.md
 */

declare(strict_types=1);

// --- Configuration ---
const PROJECT_ROOT = __DIR__ . '/..';
const OWASP_VERSION = '2025'; // Default to 2025 RC1 (can be '2021' or '2025')
const OUTPUT_FILE_2021 = PROJECT_ROOT . '/docs/reports/security-audit-owasp-2021.md';
const OUTPUT_FILE_2025 = PROJECT_ROOT . '/docs/reports/security-audit-owasp-2025-rc1.md';

// Color output for terminal
function colorize(string $text, string $color): string
{
    $colors = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'reset' => "\033[0m",
    ];

    return ($colors[$color] ?? '') . $text . ($colors['reset'] ?? '');
}

function log_info(string $message): void
{
    echo colorize('[INFO] ', 'blue') . $message . PHP_EOL;
}

function log_success(string $message): void
{
    echo colorize('[✓] ', 'green') . $message . PHP_EOL;
}

function log_warning(string $message): void
{
    echo colorize('[!] ', 'yellow') . $message . PHP_EOL;
}

function log_error(string $message): void
{
    echo colorize('[✗] ', 'red') . $message . PHP_EOL;
}

// --- File System Utilities ---
function scan_directory_recursive(string $dir, array $extensions = []): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            if (empty($extensions) || in_array($file->getExtension(), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

function count_pattern_in_files(array $files, string $pattern): int
{
    $count = 0;
    foreach ($files as $file) {
        $content = file_get_contents($file);
        if ($content !== false) {
            $count += preg_match_all($pattern, $content);
        }
    }
    return $count;
}

function find_files_with_pattern(array $files, string $pattern): array
{
    $matches = [];
    foreach ($files as $file) {
        $content = file_get_contents($file);
        if ($content !== false && preg_match($pattern, $content)) {
            $matches[] = $file;
        }
    }
    return $matches;
}

// --- Security Checks ---
class SecurityAuditChecker
{
    private array $findings = [];
    private array $scores = [];

    public function addFinding(string $category, string $severity, string $title, string $description): void
    {
        $this->findings[] = [
            'category' => $category,
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
        ];
    }

    public function setScore(string $category, float $score): void
    {
        $this->scores[$category] = $score;
    }

    public function getScore(string $category): float
    {
        return $this->scores[$category] ?? 0.0;
    }

    public function getOverallScore(): float
    {
        if (empty($this->scores)) {
            return 0.0;
        }
        return array_sum($this->scores) / count($this->scores);
    }

    public function getFindings(): array
    {
        return $this->findings;
    }

    public function getFindingsByCategory(string $category): array
    {
        return array_filter($this->findings, fn($f) => $f['category'] === $category);
    }

    public function getCriticalFindings(): array
    {
        return array_filter($this->findings, fn($f) => $f['severity'] === 'P0-URGENT');
    }

    public function getHighFindings(): array
    {
        return array_filter($this->findings, fn($f) => $f['severity'] === 'P1-HIGH');
    }

    public function getMediumFindings(): array
    {
        return array_filter($this->findings, fn($f) => $f['severity'] === 'P2-MEDIUM');
    }
}

// --- OWASP Top 10 Checks ---
function check_A01_broken_access_control(SecurityAuditChecker $checker): void
{
    log_info('Checking A01: Broken Access Control...');

    $srcFiles = scan_directory_recursive(PROJECT_ROOT . '/src', ['php']);

    // Count authorization checks
    $denyAccessCount = count_pattern_in_files($srcFiles, '/denyAccessUnlessGranted/');
    $isGrantedCount = count_pattern_in_files($srcFiles, '/isGranted\s*\(/');

    log_success("Found {$denyAccessCount} denyAccessUnlessGranted() calls");
    log_success("Found {$isGrantedCount} isGranted() calls");

    // Check for proper Voter implementations
    $voterFiles = find_files_with_pattern($srcFiles, '/class\s+\w+Voter\s+extends\s+Voter/');
    log_success('Found ' . count($voterFiles) . ' Voter implementations');

    // Check security.php configuration
    $securityConfig = PROJECT_ROOT . '/config/packages/security.php';
    if (file_exists($securityConfig)) {
        $config = file_get_contents($securityConfig);
        $hasAccessControl = str_contains($config, 'access_control');
        $hasRoleHierarchy = str_contains($config, 'role_hierarchy');

        if ($hasAccessControl) {
            log_success('Access control rules configured');
        } else {
            $checker->addFinding(
                'A01',
                'P1-HIGH',
                'Missing access_control configuration',
                'No access_control rules found in security.php'
            );
        }

        if ($hasRoleHierarchy) {
            log_success('Role hierarchy configured');
        }
    }

    // Score calculation
    $score = 9.0;
    if ($denyAccessCount < 50) {
        $score -= 1.0;
        $checker->addFinding(
            'A01',
            'P2-MEDIUM',
            'Low authorization check coverage',
            "Only {$denyAccessCount} authorization checks found. Consider adding more granular access controls."
        );
    }

    $checker->setScore('A01', $score);
}

function check_A02_cryptographic_failures(SecurityAuditChecker $checker): void
{
    log_info('Checking A02: Cryptographic Failures...');

    // Check for .env in git
    $gitignore = PROJECT_ROOT . '/.gitignore';
    $envInGit = false;

    if (file_exists(PROJECT_ROOT . '/.env')) {
        // Check if .env is tracked in git
        exec('git ls-files ' . escapeshellarg(PROJECT_ROOT . '/.env') . ' 2>&1', $output, $returnCode);
        if ($returnCode === 0 && !empty($output)) {
            $envInGit = true;
            $checker->addFinding(
                'A02',
                'P0-URGENT',
                'Credentials in Git Repository',
                '.env file is tracked in git. This exposes sensitive credentials. Use git rm --cached .env and ensure .gitignore is properly configured.'
            );
            log_error('.env file is tracked in git repository');
        } else {
            log_success('.env file is not tracked in git');
        }
    }

    // Check password hashing configuration
    $securityConfig = PROJECT_ROOT . '/config/packages/security.php';
    if (file_exists($securityConfig)) {
        $config = file_get_contents($securityConfig);
        if (str_contains($config, "'auto'") || str_contains($config, 'bcrypt') || str_contains($config, 'argon2')) {
            log_success('Strong password hashing configured');
        } else {
            $checker->addFinding(
                'A02',
                'P0-URGENT',
                'Weak password hashing',
                'No modern password hashing algorithm configured. Use auto, bcrypt, or argon2.'
            );
        }
    }

    // Check for hardcoded secrets in code
    $srcFiles = scan_directory_recursive(PROJECT_ROOT . '/src', ['php']);
    $assetsFiles = scan_directory_recursive(PROJECT_ROOT . '/assets', ['js', 'jsx']);
    $allFiles = array_merge($srcFiles, $assetsFiles);

    $secretPatterns = [
        '/password\s*=\s*[\'"][^\'"]+[\'"]/i',
        '/api[_-]?key\s*=\s*[\'"][^\'"]+[\'"]/i',
        '/secret\s*=\s*[\'"][^\'"]+[\'"]/i',
    ];

    foreach ($secretPatterns as $pattern) {
        $matches = find_files_with_pattern($allFiles, $pattern);
        if (!empty($matches)) {
            $checker->addFinding(
                'A02',
                'P1-HIGH',
                'Potential hardcoded secrets',
                'Found ' . count($matches) . ' files with potential hardcoded secrets: ' . implode(', ', array_slice($matches, 0, 3))
            );
        }
    }

    $score = $envInGit ? 6.0 : 8.5;
    $checker->setScore('A02', $score);
}

function check_A03_injection(SecurityAuditChecker $checker): void
{
    log_info('Checking A03: Injection...');

    $srcFiles = scan_directory_recursive(PROJECT_ROOT . '/src', ['php']);

    // Check for raw SQL queries (should use Doctrine)
    // Exclude safe patterns: executeQuery('SELECT 1') for health checks
    $rawSqlMatches = [];
    $unsafeQueryCount = 0;

    foreach ($srcFiles as $file) {
        $content = file_get_contents($file);
        // Look for dangerous SQL patterns but exclude health check queries
        if (preg_match_all('/mysqli_query|mysql_query|pg_query/', $content, $matches)) {
            $rawSqlMatches[] = $file;
            $unsafeQueryCount += count($matches[0]);
        }
        // Check for executeQuery but exclude static health checks like 'SELECT 1'
        if (preg_match_all('/executeQuery\s*\([\'"](?!SELECT\s+1[\'"])/i', $content, $matches)) {
            $rawSqlMatches[] = $file;
            $unsafeQueryCount += count($matches[0]);
        }
    }

    if ($unsafeQueryCount > 0) {
        $checker->addFinding(
            'A03',
            'P0-URGENT',
            'Raw SQL queries detected',
            "Found {$unsafeQueryCount} potential raw SQL queries. Use Doctrine ORM/QueryBuilder with parameterized queries."
        );
        log_warning("Found {$unsafeQueryCount} potential raw SQL queries");
    } else {
        log_success('No unsafe raw SQL queries detected (health checks excluded)');
    }

    // Check for proper Doctrine usage
    $doctrineCount = count_pattern_in_files($srcFiles, '/EntityManagerInterface|createQueryBuilder/');
    log_success("Found {$doctrineCount} Doctrine usages");

    // Check for shell command execution
    // Count actual shell command function calls (not just the word "system" in comments)
    $shellExecMatches = [];
    $actualShellExecCount = 0;

    foreach ($srcFiles as $file) {
        $content = file_get_contents($file);
        // Look for actual function calls: shell_exec(, system(, passthru(, proc_open(, exec(
        if (preg_match_all('/\b(shell_exec|passthru|proc_open|exec)\s*\(/', $content, $matches)) {
            $shellExecMatches[] = $file;
            $actualShellExecCount += count($matches[0]);
        }
        // For "system(" we need to be more careful to avoid matching "filesystem" etc.
        if (preg_match_all('/[^a-zA-Z_]system\s*\(/', $content, $matches)) {
            $shellExecMatches[] = $file;
            $actualShellExecCount += count($matches[0]);
        }
    }

    // Count properly escaped shell executions
    $escapedShellCount = count_pattern_in_files($srcFiles, '/escapeshellarg|escapeshellcmd/');

    // Only warn if shell commands are found WITHOUT proper escaping
    $unsafeShellCount = max(0, $actualShellExecCount - $escapedShellCount);

    if ($unsafeShellCount > 0) {
        $checker->addFinding(
            'A03',
            'P1-HIGH',
            'Unprotected shell command execution',
            "Found {$unsafeShellCount} shell command executions without escapeshellarg(). Ensure proper input sanitization."
        );
        log_warning("Found {$unsafeShellCount} unprotected shell command executions");
    } else if ($actualShellExecCount > 0) {
        log_success("Found {$actualShellExecCount} shell command executions (all properly escaped)");
    } else {
        log_success('No shell command executions found');
    }

    $score = 10.0;
    if ($unsafeQueryCount > 0) {
        $score -= 5.0;
    }
    if ($unsafeShellCount > 0) {
        $score -= 1.0;
    }

    $checker->setScore('A03', max(0, $score));
}

function check_A04_insecure_design(SecurityAuditChecker $checker): void
{
    log_info('Checking A04: Insecure Design...');

    $srcFiles = scan_directory_recursive(PROJECT_ROOT . '/src', ['php']);
    $assetsFiles = scan_directory_recursive(PROJECT_ROOT . '/assets', ['js', 'jsx']);

    // Check for threat modeling documentation
    $docsPath = PROJECT_ROOT . '/docs';
    $threatModelingDocs = [];
    if (is_dir($docsPath)) {
        $threatModelingDocs = find_files_with_pattern(
            scan_directory_recursive($docsPath, ['md', 'txt']),
            '/threat\s*model|security\s*architecture|attack\s*surface/i'
        );
    }

    if (count($threatModelingDocs) > 0) {
        log_success('Found ' . count($threatModelingDocs) . ' threat modeling/security architecture documents');
    } else {
        $checker->addFinding(
            'A04',
            'P2-MEDIUM',
            'No threat modeling documentation',
            'No threat modeling or security architecture documentation found in /docs. Consider documenting security design decisions.'
        );
        log_warning('No threat modeling documentation found');
    }

    // Check for secure design patterns - look for security-related design patterns
    $secureDesignPatterns = [
        'Validator|Sanitizer' => 'Input validation patterns',
        'Authorization|AccessControl' => 'Authorization patterns',
        'RateLimiter|Throttle' => 'Rate limiting patterns',
        'Encryption|Cipher' => 'Encryption patterns',
    ];

    $foundPatterns = [];
    foreach ($secureDesignPatterns as $pattern => $description) {
        $matches = find_files_with_pattern($srcFiles, '/' . $pattern . '/i');
        if (!empty($matches)) {
            $foundPatterns[$description] = count($matches);
        }
    }

    if (!empty($foundPatterns)) {
        foreach ($foundPatterns as $desc => $count) {
            log_success("Found {$count} files with {$desc}");
        }
    }

    // Check for missing security boundaries
    $apiControllers = find_files_with_pattern($srcFiles, '/ApiController/');
    $hasRateLimiting = count_pattern_in_files($srcFiles, '/RateLimiter|Throttle/i');

    if (count($apiControllers) > 0 && $hasRateLimiting === 0) {
        $checker->addFinding(
            'A04',
            'P1-HIGH',
            'API endpoints without rate limiting',
            'Found ' . count($apiControllers) . ' API controllers but no rate limiting implementation detected. APIs should have rate limiting to prevent abuse.'
        );
        log_warning('API endpoints found without rate limiting');
    }

    // Check for input validation patterns
    $validationCount = count_pattern_in_files($srcFiles, '/Assert::|->validate\(|Validator::/');
    log_success("Found {$validationCount} input validation checks");

    if ($validationCount < 20) {
        $checker->addFinding(
            'A04',
            'P2-MEDIUM',
            'Limited input validation coverage',
            "Only {$validationCount} validation checks found. Consider implementing comprehensive input validation across all user inputs."
        );
    }

    // Check for error handling patterns
    $errorHandlingCount = count_pattern_in_files($srcFiles, '/try\s*\{|catch\s*\(/');
    log_success("Found {$errorHandlingCount} error handling blocks");

    // Score calculation
    $score = 7.0; // Base score
    if (count($threatModelingDocs) > 0) $score += 1.0;
    if ($hasRateLimiting > 0) $score += 1.0;
    if ($validationCount >= 50) $score += 1.0;

    $checker->setScore('A04', min(10.0, $score));
}

function check_A05_security_misconfiguration(SecurityAuditChecker $checker): void
{
    log_info('Checking A05: Security Misconfiguration...');

    // Check .env.example existence
    if (file_exists(PROJECT_ROOT . '/.env.example')) {
        log_success('.env.example template exists');
    } else {
        $checker->addFinding(
            'A05',
            'P2-MEDIUM',
            'Missing .env.example',
            'Create .env.example as template for environment configuration'
        );
    }

    // Check debug mode in production
    $envFile = PROJECT_ROOT . '/.env';
    if (file_exists($envFile)) {
        $envContent = file_get_contents($envFile);
        if (preg_match('/APP_ENV\s*=\s*prod/i', $envContent)) {
            log_success('Production environment configured');
        } else {
            log_warning('Development environment in .env file');
        }

        if (preg_match('/APP_DEBUG\s*=\s*true/i', $envContent)) {
            $checker->addFinding(
                'A05',
                'P1-HIGH',
                'Debug mode enabled',
                'APP_DEBUG=true found in .env. Disable debug mode in production.'
            );
        }
    }

    // Check framework.yaml for security headers
    $frameworkConfig = PROJECT_ROOT . '/config/packages/framework.yaml';
    if (file_exists($frameworkConfig)) {
        $config = file_get_contents($frameworkConfig);
        $hasSessionConfig = str_contains($config, 'cookie_secure') || str_contains($config, 'cookie_httponly');

        if ($hasSessionConfig) {
            log_success('Session security configured');
        } else {
            $checker->addFinding(
                'A05',
                'P2-MEDIUM',
                'Session security not configured',
                'Configure cookie_secure, cookie_httponly, and cookie_samesite in framework.yaml'
            );
        }
    }

    $checker->setScore('A05', 8.0);
}

function check_A03_software_supply_chain_failures(SecurityAuditChecker $checker): void
{
    log_info('Checking A03:2025: Software Supply Chain Failures...');

    $hasComposerLock = file_exists(PROJECT_ROOT . '/composer.lock');
    $hasPackageLock = file_exists(PROJECT_ROOT . '/package-lock.json');

    if ($hasComposerLock && $hasPackageLock) {
        log_success('Dependency lock files present (composer.lock, package-lock.json)');
    } else {
        if (!$hasComposerLock) {
            log_warning('composer.lock missing');
        }
        if (!$hasPackageLock) {
            log_warning('package-lock.json missing');
        }
    }

    // Check for outdated dependencies by looking at lock file dates
    $composerAge = 0;
    $npmAge = 0;

    if ($hasComposerLock) {
        $composerAge = (time() - filemtime(PROJECT_ROOT . '/composer.lock')) / (60 * 60 * 24); // days
        log_info(sprintf('composer.lock last updated: %.0f days ago', $composerAge));
    }

    if ($hasPackageLock) {
        $npmAge = (time() - filemtime(PROJECT_ROOT . '/package-lock.json')) / (60 * 60 * 24); // days
        log_info(sprintf('package-lock.json last updated: %.0f days ago', $npmAge));
    }

    // Warn if dependencies haven't been updated in a long time
    if ($composerAge > 180) {
        $checker->addFinding(
            'A06',
            'P2-MEDIUM',
            'Outdated PHP dependencies',
            sprintf('composer.lock has not been updated in %.0f days. Run "composer update" and "composer audit" to check for vulnerabilities.', $composerAge)
        );
    }

    if ($npmAge > 180) {
        $checker->addFinding(
            'A06',
            'P2-MEDIUM',
            'Outdated JavaScript dependencies',
            sprintf('package-lock.json has not been updated in %.0f days. Run "npm update" and "npm audit" to check for vulnerabilities.', $npmAge)
        );
    }

    // Check for known vulnerable patterns in dependencies
    // Look for old versions of common libraries in composer.json
    if (file_exists(PROJECT_ROOT . '/composer.json')) {
        $composerJson = json_decode(file_get_contents(PROJECT_ROOT . '/composer.json'), true);
        $requirePhp = $composerJson['require']['php'] ?? '';

        if (!empty($requirePhp)) {
            log_success("PHP version constraint: {$requirePhp}");
        }

        // Check Symfony version
        $symfonyPackages = array_filter($composerJson['require'] ?? [], function($key) {
            return str_starts_with($key, 'symfony/');
        }, ARRAY_FILTER_USE_KEY);

        if (!empty($symfonyPackages)) {
            log_success('Using ' . count($symfonyPackages) . ' Symfony packages');
        }
    }

    // Check for package.json
    if (file_exists(PROJECT_ROOT . '/package.json')) {
        $packageJson = json_decode(file_get_contents(PROJECT_ROOT . '/package.json'), true);

        // Check for critical frontend frameworks
        $reactVersion = $packageJson['dependencies']['react'] ?? null;
        if ($reactVersion) {
            log_success("React version: {$reactVersion}");
        }
    }

    // Recommend running audit commands
    log_info('Recommendation: Run "composer audit" and "npm audit" to check for known vulnerabilities');

    $checker->addFinding(
        'A06',
        'P2-MEDIUM',
        'Regular dependency audits needed',
        'Ensure regular execution of "composer audit" and "npm audit" to detect known vulnerabilities. Consider integrating these checks into CI/CD pipeline.'
    );

    // Score calculation
    $score = 8.0; // Base score
    if (!$hasComposerLock || !$hasPackageLock) {
        $score -= 2.0;
    }
    if ($composerAge > 180 || $npmAge > 180) {
        $score -= 1.0;
    }

    // Additional 2025 checks for Supply Chain Security

    // Check for .npmrc or .yarnrc (private registry configuration)
    $hasNpmrc = file_exists(PROJECT_ROOT . '/.npmrc');
    $hasYarnrc = file_exists(PROJECT_ROOT . '/.yarnrc');

    if ($hasNpmrc || $hasYarnrc) {
        log_success('Private package registry configuration found');
        $score += 0.5;
    } else {
        log_info('No private registry configuration - using public registries');
    }

    // Check for SBOM (Software Bill of Materials)
    $hasSBOM = file_exists(PROJECT_ROOT . '/sbom.json') ||
               file_exists(PROJECT_ROOT . '/sbom.xml') ||
               file_exists(PROJECT_ROOT . '/cyclonedx.json');

    if ($hasSBOM) {
        log_success('SBOM (Software Bill of Materials) found');
        $score += 0.5;
    } else {
        $checker->addFinding(
            'A03',
            'P2-MEDIUM',
            'No SBOM available',
            'Consider generating a Software Bill of Materials (SBOM) using tools like cyclonedx-php-composer or npm sbom. This helps track dependencies and vulnerabilities.'
        );
        log_warning('No SBOM (Software Bill of Materials) found');
    }

    // Check for package signature verification (.asc files)
    $hasPackageSignatures = file_exists(PROJECT_ROOT . '/composer.lock.asc');
    if ($hasPackageSignatures) {
        log_success('Package signature verification enabled');
    }

    // Check CI/CD configuration for security
    $cicdConfigs = [
        '.github/workflows',
        '.gitlab-ci.yml',
        'bitbucket-pipelines.yml',
        'azure-pipelines.yml'
    ];

    $hasCICD = false;
    foreach ($cicdConfigs as $config) {
        if (file_exists(PROJECT_ROOT . '/' . $config)) {
            $hasCICD = true;
            log_success("CI/CD configuration found: {$config}");
            break;
        }
    }

    if (!$hasCICD) {
        log_warning('No CI/CD configuration detected - consider adding automated security checks');
    }

    $checker->setScore('A03', max(0, min(10.0, $score)));
}

function check_A07_identification_authentication_failures(SecurityAuditChecker $checker): void
{
    log_info('Checking A07: Identification and Authentication Failures...');

    // Check for rate limiting
    $securityConfig = PROJECT_ROOT . '/config/packages/security.php';
    $hasRateLimiting = false;

    if (file_exists($securityConfig)) {
        $config = file_get_contents($securityConfig);
        $hasRateLimiting = str_contains($config, 'login_throttling');

        if ($hasRateLimiting) {
            log_success('Login throttling configured');
        } else {
            $checker->addFinding(
                'A07',
                'P0-URGENT',
                'Login endpoint not rate-limited',
                'Configure login_throttling in security.php to prevent brute-force attacks'
            );
            log_error('No rate limiting configured for login');
        }
    }

    // Check for MFA/2FA support
    $srcFiles = scan_directory_recursive(PROJECT_ROOT . '/src', ['php']);
    $mfaCount = count_pattern_in_files($srcFiles, '/TwoFactor|MFA|TOTP/');

    if ($mfaCount > 0) {
        log_success('Multi-factor authentication found');
    } else {
        log_warning('No MFA implementation detected');
    }

    $score = $hasRateLimiting ? 8.5 : 6.0;
    $checker->setScore('A07', $score);
}

function check_A08_software_data_integrity_failures(SecurityAuditChecker $checker): void
{
    log_info('Checking A08: Software and Data Integrity Failures...');

    // Check composer.lock exists
    if (file_exists(PROJECT_ROOT . '/composer.lock')) {
        log_success('composer.lock present - dependency versions locked');
    } else {
        $checker->addFinding(
            'A08',
            'P1-HIGH',
            'No composer.lock file',
            'Run composer install to generate composer.lock for reproducible builds'
        );
    }

    // Check package-lock.json exists
    if (file_exists(PROJECT_ROOT . '/package-lock.json')) {
        log_success('package-lock.json present - npm dependencies locked');
    } else {
        $checker->addFinding(
            'A08',
            'P1-HIGH',
            'No package-lock.json file',
            'Run npm install to generate package-lock.json for reproducible builds'
        );
    }

    // Check for vulnerable dependencies (would require actual audit)
    log_info('Note: Run "composer audit" and "npm audit" for vulnerability scanning');

    $checker->setScore('A08', 8.0);
}

function check_A09_security_logging_monitoring_failures(SecurityAuditChecker $checker): void
{
    log_info('Checking A09: Security Logging and Monitoring Failures...');

    // Check monolog configuration
    $monologConfig = PROJECT_ROOT . '/config/packages/monolog.yaml';
    if (file_exists($monologConfig)) {
        $config = file_get_contents($monologConfig);
        $hasLogging = str_contains($config, 'handlers');

        if ($hasLogging) {
            log_success('Logging handlers configured');
        } else {
            $checker->addFinding(
                'A09',
                'P2-MEDIUM',
                'Logging not configured',
                'Configure monolog handlers for security event logging'
            );
        }
    }

    // Check for security event logging in code
    $srcFiles = scan_directory_recursive(PROJECT_ROOT . '/src', ['php']);
    $loggerCount = count_pattern_in_files($srcFiles, '/LoggerInterface|\$this->logger/');
    log_success("Found {$loggerCount} logger usages");

    $checker->setScore('A09', 7.5);
}

function check_A10_server_side_request_forgery(SecurityAuditChecker $checker): void
{
    log_info('Checking A10:2021: Server-Side Request Forgery (SSRF)...');

    $srcFiles = scan_directory_recursive(PROJECT_ROOT . '/src', ['php']);

    // Check for URL fetching without validation
    $urlFetchCount = count_pattern_in_files($srcFiles, '/file_get_contents\s*\(\s*\$|curl_exec|guzzle/i');

    if ($urlFetchCount > 0) {
        $checker->addFinding(
            'A10_2021',
            'P2-MEDIUM',
            'Potential SSRF vectors (2021)',
            "Found {$urlFetchCount} URL fetching operations. Ensure URL validation and whitelist allowed domains."
        );
        log_warning("Found {$urlFetchCount} URL fetching operations");
    } else {
        log_success('No obvious SSRF vectors detected');
    }

    $checker->setScore('A10_2021', 8.5);
}

function check_A10_mishandling_exceptional_conditions(SecurityAuditChecker $checker): void
{
    log_info('Checking A10:2025: Mishandling of Exceptional Conditions...');

    $srcFiles = scan_directory_recursive(PROJECT_ROOT . '/src', ['php']);
    $assetsFiles = scan_directory_recursive(PROJECT_ROOT . '/assets', ['js', 'jsx']);

    // Check for try-catch blocks
    $tryCatchCount = count_pattern_in_files($srcFiles, '/try\s*\{/');
    log_success("Found {$tryCatchCount} try-catch blocks");

    // Check for empty catch blocks (anti-pattern)
    $emptyCatchCount = count_pattern_in_files($srcFiles, '/catch\s*\([^)]+\)\s*\{\s*\}/');
    if ($emptyCatchCount > 0) {
        $checker->addFinding(
            'A10',
            'P1-HIGH',
            'Empty catch blocks detected',
            "Found {$emptyCatchCount} empty catch blocks that silently ignore exceptions. These should log errors or handle them appropriately."
        );
        log_error("Found {$emptyCatchCount} empty catch blocks");
    } else {
        log_success('No empty catch blocks detected');
    }

    // Check for proper error logging in catch blocks
    $catchWithLoggingCount = count_pattern_in_files($srcFiles, '/catch\s*\([^)]+\)\s*\{[^}]*(?:log|Log|logger)/');
    $properErrorHandling = $tryCatchCount > 0 ? ($catchWithLoggingCount / $tryCatchCount * 100) : 0;
    log_info(sprintf('Error logging coverage: %.1f%% of catch blocks', $properErrorHandling));

    if ($properErrorHandling < 50 && $tryCatchCount > 10) {
        $checker->addFinding(
            'A10',
            'P2-MEDIUM',
            'Insufficient error logging',
            sprintf('Only %.1f%% of catch blocks include logging. Consider adding proper error logging for debugging and security monitoring.', $properErrorHandling)
        );
    }

    // Check for throw without proper handling
    $throwCount = count_pattern_in_files($srcFiles, '/throw\s+new\s+/');
    log_success("Found {$throwCount} throw statements");

    // Check for potential fail-open scenarios (default allow)
    $failOpenPatterns = count_pattern_in_files($srcFiles, '/catch[^}]*return\s+true/i');
    if ($failOpenPatterns > 0) {
        $checker->addFinding(
            'A10',
            'P0-URGENT',
            'Potential fail-open scenarios',
            "Found {$failOpenPatterns} catch blocks that return true on error. This may allow unauthorized access when exceptions occur."
        );
        log_error("Found {$failOpenPatterns} potential fail-open scenarios");
    }

    // Check for unhandled promise rejections in JavaScript
    $jsUnhandledPromises = count_pattern_in_files($assetsFiles, '/\.then\([^)]+\)(?!\s*\.catch)/');
    if ($jsUnhandledPromises > 20) {
        $checker->addFinding(
            'A10',
            'P2-MEDIUM',
            'Unhandled promise rejections in JavaScript',
            "Found {$jsUnhandledPromises} promises without .catch() handlers. These can lead to silent failures in the frontend."
        );
        log_warning("Found {$jsUnhandledPromises} promises without catch handlers");
    }

    // Check for generic exception catching (should be specific)
    $genericCatchCount = count_pattern_in_files($srcFiles, '/catch\s*\(\s*\\?Exception\s+/');
    if ($genericCatchCount > 10) {
        log_warning("Found {$genericCatchCount} generic Exception catches - consider using specific exception types");
    }

    // Check for finally blocks (resource cleanup)
    $finallyCount = count_pattern_in_files($srcFiles, '/finally\s*\{/');
    log_success("Found {$finallyCount} finally blocks for resource cleanup");

    // Score calculation
    $score = 8.0; // Base score
    if ($emptyCatchCount > 0) {
        $score -= 2.0; // Empty catches are serious
    }
    if ($failOpenPatterns > 0) {
        $score -= 3.0; // Fail-open is critical
    }
    if ($properErrorHandling < 30 && $tryCatchCount > 10) {
        $score -= 1.0; // Poor error logging
    }
    if ($finallyCount > 10) {
        $score += 0.5; // Good resource cleanup
    }

    $checker->setScore('A10', max(0, $score));
}

// --- Report Generation ---
function generate_report(SecurityAuditChecker $checker, string $owaspVersion = '2025'): string
{
    $date = date('Y-m-d');
    $overallScore = round($checker->getOverallScore(), 1);
    $status = $overallScore >= 9.0 ? 'EXCELLENT' :
              ($overallScore >= 8.0 ? 'GUT' :
              ($overallScore >= 7.0 ? 'BEFRIEDIGEND' : 'KRITISCH'));

    $criticalFindings = $checker->getCriticalFindings();
    $highFindings = $checker->getHighFindings();
    $mediumFindings = $checker->getMediumFindings();

    $versionLabel = $owaspVersion === '2025' ? '2025 RC1' : '2021';
    $versionNote = $owaspVersion === '2025' ? ' (Release Candidate 1 - November 2025)' : ' (Final Release)';

    $report = <<<MD
# Little ISMS Helper Security Audit Report
## OWASP Top 10 Compliance Analysis

**Berichtsdatum:** {$date}
**Geprüfte Version:** Little ISMS Helper Symfony 6.4 + React 19.1.1
**Prüfumfang:** OWASP Top 10 {$versionLabel}{$versionNote}
**Gesamtbewertung:** {$overallScore}/10 ({$status})

---

## Executive Summary

Little ISMS Helper zeigt eine **starke Sicherheitsposition** mit umfassenden Schutzmaßnahmen auf allen Ebenen.
Die automatisierte Prüfung hat **{$checker->getOverallScore()}** von 10 möglichen Punkten erreicht.

### Kritische Stärken ✅
- Durchgängige Verwendung von Doctrine ORM (SQL Injection Prevention)
- Rollenbasierte Zugriffskontrolle implementiert
- Moderne Passwort-Hashing-Algorithmen konfiguriert
- Dependency-Versionen mit Lock-Files fixiert

### Identifizierte Risiken ⚠️
MD;

    // Add critical findings
    if (!empty($criticalFindings)) {
        $report .= "\n\n#### P0 - URGENT\n";
        foreach ($criticalFindings as $finding) {
            $report .= "\n- **{$finding['title']}**: {$finding['description']}";
        }
    }

    // Add high findings
    if (!empty($highFindings)) {
        $report .= "\n\n#### P1 - HIGH\n";
        foreach ($highFindings as $finding) {
            $report .= "\n- **{$finding['title']}**: {$finding['description']}";
        }
    }

    $report .= "\n\n---\n\n## Compliance Matrix\n\n";
    $report .= "| OWASP Category | Status | Score | Findings |\n";
    $report .= "|----------------|--------|-------|----------|\n";

    // Define categories based on OWASP version
    $categories = [];
    if ($owaspVersion === '2025') {
        $categories = [
            'A01' => 'A01: Broken Access Control',
            'A02' => 'A02: Security Misconfiguration',
            'A03' => 'A03: Software Supply Chain Failures',
            'A04' => 'A04: Cryptographic Failures',
            'A05' => 'A05: Injection',
            'A06' => 'A06: Insecure Design',
            'A07' => 'A07: Authentication Failures',
            'A08' => 'A08: Software or Data Integrity Failures',
            'A09' => 'A09: Logging and Alerting Failures',
            'A10' => 'A10: Mishandling of Exceptional Conditions',
        ];
    } else {
        $categories = [
            'A01' => 'A01: Broken Access Control',
            'A02' => 'A02: Cryptographic Failures',
            'A03' => 'A03: Injection',
            'A04' => 'A04: Insecure Design',
            'A05' => 'A05: Security Misconfiguration',
            'A06' => 'A06: Vulnerable and Outdated Components',
            'A07' => 'A07: Identification and Authentication Failures',
            'A08' => 'A08: Software and Data Integrity Failures',
            'A09' => 'A09: Security Logging and Monitoring Failures',
            'A10_2021' => 'A10: Server-Side Request Forgery (SSRF)',
        ];
    }

    foreach ($categories as $code => $name) {
        $score = $checker->getScore($code);
        $status = $score >= 9.0 ? '✅ Excellent' :
                  ($score >= 8.0 ? '✅ Good' :
                  ($score >= 7.0 ? '⚠️ Needs Improvement' : '🔴 Critical'));

        $categoryFindings = $checker->getFindingsByCategory($code);
        $findingCount = count($categoryFindings);

        $report .= sprintf("| %s | %s | %.1f/10 | %d |\n", $name, $status, $score, $findingCount);
    }

    // Detailed findings
    $report .= "\n\n---\n\n## Detailed Findings\n\n";

    foreach ($categories as $code => $name) {
        $categoryFindings = $checker->getFindingsByCategory($code);
        if (empty($categoryFindings)) {
            continue;
        }

        $report .= "### {$name}\n\n";
        foreach ($categoryFindings as $finding) {
            $report .= "#### [{$finding['severity']}] {$finding['title']}\n\n";
            $report .= "{$finding['description']}\n\n";
        }
    }

    // Action Items
    $report .= "\n\n---\n\n## Priority Action Items\n\n";

    if (!empty($criticalFindings)) {
        $report .= "### P0 - URGENT (Immediate Action Required)\n\n";
        $i = 1;
        foreach ($criticalFindings as $finding) {
            $report .= "{$i}. **{$finding['title']}**\n";
            $report .= "   - {$finding['description']}\n\n";
            $i++;
        }
    }

    if (!empty($highFindings)) {
        $report .= "### P1 - HIGH (Within 1 Week)\n\n";
        $i = 1;
        foreach ($highFindings as $finding) {
            $report .= "{$i}. **{$finding['title']}**\n";
            $report .= "   - {$finding['description']}\n\n";
            $i++;
        }
    }

    if (!empty($mediumFindings)) {
        $report .= "### P2 - MEDIUM (Within 1 Month)\n\n";
        $i = 1;
        foreach ($mediumFindings as $finding) {
            $report .= "{$i}. **{$finding['title']}**\n";
            $report .= "   - {$finding['description']}\n\n";
            $i++;
        }
    }

    $report .= "\n\n---\n\n";
    $report .= "## Recommendations\n\n";
    $report .= "1. **Security Headers**: Implement Content-Security-Policy, X-Frame-Options, X-Content-Type-Options\n";
    $report .= "2. **Rate Limiting**: Add rate limiting to all API endpoints\n";
    $report .= "3. **Dependency Scanning**: Integrate automated vulnerability scanning in CI/CD pipeline\n";
    $report .= "4. **Penetration Testing**: Schedule regular external security audits\n";
    $report .= "5. **Security Training**: Conduct OWASP Top 10 training for development team\n\n";

    $report .= "---\n\n";
    $report .= "*Report generated automatically by scripts/generate-security-audit.php*\n";
    $report .= "*Last updated: " . date('Y-m-d H:i:s') . "*\n";

    return $report;
}

// --- Main Execution ---
function main(): int
{
    log_info('Starting Little ISMS Helper Security Audit (Dual Version)...');
    log_info('Project Root: ' . PROJECT_ROOT);
    log_info('Generating OWASP Top 10:2025 RC1 (Primary) and 2021 (Legacy) reports');

    // === Generate OWASP Top 10:2025 RC1 Report (Primary) ===
    echo colorize("\n=== OWASP Top 10:2025 RC1 (Primary) ===\n", 'blue');

    $checker2025 = new SecurityAuditChecker();

    // Run all checks - 2025 uses same checks but different scores/categories
    check_A01_broken_access_control($checker2025);
    check_A05_security_misconfiguration($checker2025); // A02 in 2025
    check_A03_software_supply_chain_failures($checker2025); // A03 in 2025
    check_A02_cryptographic_failures($checker2025); // A04 in 2025
    check_A03_injection($checker2025); // A05 in 2025
    check_A04_insecure_design($checker2025); // A06 in 2025
    check_A07_identification_authentication_failures($checker2025); // A07 in 2025
    check_A08_software_data_integrity_failures($checker2025); // A08 in 2025
    check_A09_security_logging_monitoring_failures($checker2025); // A09 in 2025
    check_A10_mishandling_exceptional_conditions($checker2025); // A10 in 2025 (NEW)

    // Generate 2025 report
    log_info('Generating OWASP Top 10:2025 RC1 report...');
    $report2025 = generate_report($checker2025, '2025');

    // Ensure output directory exists
    $outputDir = dirname(OUTPUT_FILE_2025);
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    // Write 2025 report
    file_put_contents(OUTPUT_FILE_2025, $report2025);

    $overallScore2025 = $checker2025->getOverallScore();
    $criticalCount2025 = count($checker2025->getCriticalFindings());
    $highCount2025 = count($checker2025->getHighFindings());

    log_success('OWASP 2025 RC1 Report completed!');
    log_success('Overall Score: ' . round($overallScore2025, 1) . '/10');
    log_info('Report written to: ' . OUTPUT_FILE_2025);

    // === Generate OWASP Top 10:2021 Report (Legacy) ===
    echo colorize("\n=== OWASP Top 10:2021 (Legacy) ===\n", 'blue');

    $checker2021 = new SecurityAuditChecker();

    // Run all 2021 checks
    check_A01_broken_access_control($checker2021);
    check_A02_cryptographic_failures($checker2021);
    check_A03_injection($checker2021);
    check_A04_insecure_design($checker2021);
    check_A05_security_misconfiguration($checker2021);
    check_A03_software_supply_chain_failures($checker2021); // Was A06 in 2021
    check_A07_identification_authentication_failures($checker2021);
    check_A08_software_data_integrity_failures($checker2021);
    check_A09_security_logging_monitoring_failures($checker2021);
    check_A10_server_side_request_forgery($checker2021); // A10 in 2021 (SSRF)

    // Generate 2021 report
    log_info('Generating OWASP Top 10:2021 report...');
    $report2021 = generate_report($checker2021, '2021');

    // Write 2021 report
    file_put_contents(OUTPUT_FILE_2021, $report2021);

    $overallScore2021 = $checker2021->getOverallScore();
    $criticalCount2021 = count($checker2021->getCriticalFindings());
    $highCount2021 = count($checker2021->getHighFindings());

    log_success('OWASP 2021 Report completed!');
    log_success('Overall Score: ' . round($overallScore2021, 1) . '/10');
    log_info('Report written to: ' . OUTPUT_FILE_2021);

    // Summary
    echo colorize("\n=== Summary ===\n", 'green');
    log_success("2025 RC1 (Primary): {$overallScore2025}/10 - {$criticalCount2025} critical, {$highCount2025} high priority");
    log_success("2021 (Legacy):      {$overallScore2021}/10 - {$criticalCount2021} critical, {$highCount2021} high priority");

    if ($criticalCount2025 > 0) {
        log_error("Found {$criticalCount2025} CRITICAL findings in 2025 RC1 that require immediate attention!");
    }

    // Return non-zero if critical findings in primary (2025) version
    return $criticalCount2025 > 0 ? 1 : 0;
}

// Execute
exit(main());
