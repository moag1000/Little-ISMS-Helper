#!/usr/bin/env php
<?php

/**
 * Automatically fix missing tenant_id columns
 * Usage: php fix_missing_tenant_columns.php
 */

require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Load environment
(new Dotenv())->bootEnv(__DIR__.'/.env');

// Expected tables from migration
$expectedTables = [
    'asset', 'audit_checklist', 'bc_exercise', 'business_continuity_plan',
    'business_process', 'change_request', 'control', 'crisis_teams',
    'cryptographic_operation', 'document', 'incident', 'interested_party',
    'internal_audit', 'isms_context', 'isms_objective', 'location',
    'management_review', 'patches', 'person', 'physical_access_log',
    'risk', 'risk_appetite', 'risk_treatment_plan', 'supplier',
    'threat_intelligence', 'training', 'users', 'vulnerabilities',
    'workflows', 'workflow_instances', 'workflow_steps',
];

// Parse DATABASE_URL
$databaseUrl = $_ENV['DATABASE_URL'] ?? null;
if (!$databaseUrl) {
    echo "❌ DATABASE_URL not found\n";
    exit(1);
}

preg_match('/([^:]+):\/\/([^:]+):([^@]+)@([^:]+):(\d+)\/(.+)/', $databaseUrl, $matches);
if (count($matches) !== 7) {
    echo "❌ Could not parse DATABASE_URL\n";
    exit(1);
}

[, $driver, $user, $password, $host, $port, $database] = $matches;
$database = explode('?', $database)[0];

echo "🔧 Fixing missing tenant_id columns in: $database\n";
echo str_repeat("=", 80) . "\n\n";

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$database",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $fixed = 0;
    $skipped = 0;
    $notFound = 0;

    foreach ($expectedTables as $table) {
        // Check if table exists
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = ? AND table_name = ?
        ");
        $stmt->execute([$database, $table]);

        if (!$stmt->fetchColumn()) {
            echo "⏭️  Skipping $table (table not found)\n";
            $notFound++;
            continue;
        }

        // Check if column exists
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = ? AND table_name = ? AND column_name = 'tenant_id'
        ");
        $stmt->execute([$database, $table]);

        if ($stmt->fetchColumn() > 0) {
            echo "✅ Skipping $table (tenant_id already exists)\n";
            $skipped++;
            continue;
        }

        // Add the column
        echo "🔧 Adding tenant_id to $table... ";
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `tenant_id` INT DEFAULT NULL");
        echo "✅ Done\n";
        $fixed++;
    }

    echo "\n" . str_repeat("=", 80) . "\n";
    echo "📊 Summary:\n";
    echo "   ✨ Fixed (added tenant_id): $fixed tables\n";
    echo "   ✅ Skipped (already exists): $skipped tables\n";
    echo "   ⏭️  Skipped (not found): $notFound tables\n";
    echo "\n";

    if ($fixed > 0) {
        echo "✅ SUCCESS: Added tenant_id to $fixed tables\n\n";
        echo "Next steps:\n";
        echo "   1. Clear cache: php bin/console cache:clear\n";
        echo "   2. Restart PHP-FPM/Apache: sudo systemctl restart php-fpm\n";
        echo "   3. Test the application\n";
    } else {
        echo "ℹ️  No changes needed - all tables already have tenant_id\n\n";
        echo "If you still get errors, the issue is elsewhere:\n";
        echo "   1. Check entity definitions\n";
        echo "   2. Clear all caches (Symfony + OPcache)\n";
        echo "   3. Check database connection\n";
    }

} catch (PDOException $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
