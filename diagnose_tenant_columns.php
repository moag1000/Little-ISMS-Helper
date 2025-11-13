#!/usr/bin/env php
<?php

/**
 * Diagnose which tables have tenant_id column and which don't
 * Usage: php diagnose_tenant_columns.php
 */

require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Load environment
(new Dotenv())->bootEnv(__DIR__.'/.env');

// Expected tables from migration
$expectedTables = [
    'asset',
    'audit_checklist',
    'bc_exercise',
    'business_continuity_plan',
    'business_process',
    'change_request',
    'control',
    'crisis_teams',
    'cryptographic_operation',
    'document',
    'incident',
    'interested_party',
    'internal_audit',
    'isms_context',
    'isms_objective',
    'location',
    'management_review',
    'patches',
    'person',
    'physical_access_log',
    'risk',
    'risk_appetite',
    'risk_treatment_plan',
    'supplier',
    'threat_intelligence',
    'training',
    'users',
    'vulnerabilities',
    'workflows',
    'workflow_instances',
    'workflow_steps',
];

// Parse DATABASE_URL
$databaseUrl = $_ENV['DATABASE_URL'] ?? null;
if (!$databaseUrl) {
    echo "❌ DATABASE_URL not found in environment\n";
    exit(1);
}

// Extract database connection info
preg_match('/([^:]+):\/\/([^:]+):([^@]+)@([^:]+):(\d+)\/(.+)/', $databaseUrl, $matches);
if (count($matches) !== 7) {
    echo "❌ Could not parse DATABASE_URL\n";
    exit(1);
}

[, $driver, $user, $password, $host, $port, $database] = $matches;

// Remove query parameters from database name
$database = explode('?', $database)[0];

echo "🔍 Diagnosing tenant_id columns in database: $database\n";
echo str_repeat("=", 80) . "\n\n";

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$database",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $tablesWithTenantId = [];
    $tablesWithoutTenantId = [];
    $tablesNotFound = [];

    foreach ($expectedTables as $table) {
        // Check if table exists
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = ? AND table_name = ?
        ");
        $stmt->execute([$database, $table]);
        $tableExists = $stmt->fetchColumn() > 0;

        if (!$tableExists) {
            $tablesNotFound[] = $table;
            continue;
        }

        // Check if tenant_id column exists
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = ? AND table_name = ? AND column_name = 'tenant_id'
        ");
        $stmt->execute([$database, $table]);
        $columnExists = $stmt->fetchColumn() > 0;

        if ($columnExists) {
            $tablesWithTenantId[] = $table;
        } else {
            $tablesWithoutTenantId[] = $table;
        }
    }

    // Print results
    echo "✅ Tables WITH tenant_id column (" . count($tablesWithTenantId) . "):\n";
    if (count($tablesWithTenantId) > 0) {
        foreach ($tablesWithTenantId as $table) {
            echo "   ✓ $table\n";
        }
    } else {
        echo "   (none)\n";
    }
    echo "\n";

    echo "❌ Tables WITHOUT tenant_id column (" . count($tablesWithoutTenantId) . "):\n";
    if (count($tablesWithoutTenantId) > 0) {
        foreach ($tablesWithoutTenantId as $table) {
            echo "   ✗ $table\n";
        }
    } else {
        echo "   (none)\n";
    }
    echo "\n";

    echo "⚠️  Tables NOT FOUND in database (" . count($tablesNotFound) . "):\n";
    if (count($tablesNotFound) > 0) {
        foreach ($tablesNotFound as $table) {
            echo "   ? $table\n";
        }
    } else {
        echo "   (none)\n";
    }
    echo "\n";

    echo str_repeat("=", 80) . "\n";
    echo "📊 Summary:\n";
    echo "   ✅ With tenant_id: " . count($tablesWithTenantId) . " / " . count($expectedTables) . "\n";
    echo "   ❌ Without tenant_id: " . count($tablesWithoutTenantId) . " / " . count($expectedTables) . "\n";
    echo "   ⚠️  Not found: " . count($tablesNotFound) . " / " . count($expectedTables) . "\n";
    echo "\n";

    // Generate SQL to fix missing columns
    if (count($tablesWithoutTenantId) > 0) {
        echo "🔧 SQL to add missing tenant_id columns:\n";
        echo str_repeat("-", 80) . "\n";
        foreach ($tablesWithoutTenantId as $table) {
            echo "ALTER TABLE `$table` ADD COLUMN `tenant_id` INT DEFAULT NULL;\n";
        }
        echo str_repeat("-", 80) . "\n";
        echo "\n";
        echo "💡 To execute these:\n";
        echo "   1. Copy the SQL statements above\n";
        echo "   2. Run: mysql -u $user -p $database < fix.sql\n";
        echo "   3. Or run manually in your database client\n";
        echo "\n";
    }

    if (count($tablesWithoutTenantId) === 0 && count($tablesNotFound) === count($expectedTables)) {
        echo "⚠️  WARNING: No tables found! This might indicate:\n";
        echo "   - Wrong database name\n";
        echo "   - Database not initialized\n";
        echo "   - Connection issues\n";
        exit(1);
    }

    if (count($tablesWithoutTenantId) > 0) {
        echo "❌ RESULT: Migration did NOT add tenant_id to all tables\n";
        exit(1);
    } else {
        echo "✅ RESULT: All tables have tenant_id column!\n";
        echo "\nIf you still get errors, try:\n";
        echo "   1. Clear cache: php bin/console cache:clear\n";
        echo "   2. Check entity definitions match database schema\n";
        echo "   3. Restart PHP-FPM/Apache\n";
        exit(0);
    }

} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    exit(1);
}
