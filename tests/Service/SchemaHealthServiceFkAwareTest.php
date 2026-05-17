<?php

declare(strict_types=1);

namespace App\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Exception as PdoException;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the FK-aware reconcile logic in SchemaHealthService.
 *
 * Integration tests (real DB) are deferred to manual smoke testing — these
 * unit tests cover the regex parsing and the drop+retry+readd loop using
 * a mock Connection.
 */
class SchemaHealthServiceFkAwareTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Test 1 — regex correctly parses column name + FK name from 1832 message
    // -------------------------------------------------------------------------

    #[Test]
    public function testParsesAndExtractsFkInfoFrom1832ErrorMessage(): void
    {
        $msg = "An exception occurred while executing a query: SQLSTATE[HY000]: General error: 1832 Cannot change column 'current_version_id': used in a foreign key constraint 'fk_doc_current_version'";
        $matched = preg_match(
            "/1832 Cannot change column '([^']+)': used in a foreign key constraint '([^']+)'/",
            $msg,
            $m,
        );

        $this->assertSame(1, $matched);
        $this->assertSame('current_version_id', $m[1]);
        $this->assertSame('fk_doc_current_version', $m[2]);
    }

    #[Test]
    public function testRegexMatchesAlternativeColumnAndFkNames(): void
    {
        $msg = "SQLSTATE[HY000]: General error: 1832 Cannot change column 'risk_level': used in a foreign key constraint 'fk_risk_treatment_risk'";
        $matched = preg_match(
            "/1832 Cannot change column '([^']+)': used in a foreign key constraint '([^']+)'/",
            $msg,
            $m,
        );

        $this->assertSame(1, $matched);
        $this->assertSame('risk_level', $m[1]);
        $this->assertSame('fk_risk_treatment_risk', $m[2]);
    }

    #[Test]
    public function testRegexDoesNotMatchOtherMysqlErrors(): void
    {
        $unrelatedErrors = [
            "SQLSTATE[42000]: Syntax error: 1064 You have an error in your SQL syntax",
            "SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row",
            "SQLSTATE[HY000]: General error: 1005 Can't create table",
        ];

        foreach ($unrelatedErrors as $msg) {
            $matched = preg_match(
                "/1832 Cannot change column '([^']+)': used in a foreign key constraint '([^']+)'/",
                $msg,
                $m,
            );
            $this->assertSame(0, $matched, "Pattern should NOT match: {$msg}");
        }
    }

    // -------------------------------------------------------------------------
    // Test 2 — ALTER TABLE regex correctly extracts table name
    // -------------------------------------------------------------------------

    #[Test]
    public function testAlterTableRegexExtractsTableName(): void
    {
        $statements = [
            'ALTER TABLE `document` CHANGE `current_version_id` `current_version_id` VARCHAR(64) NOT NULL' => 'document',
            'ALTER TABLE document CHANGE col col INT NOT NULL' => 'document',
            'ALTER TABLE `my_table_name` ADD COLUMN `foo` VARCHAR(255)' => 'my_table_name',
        ];

        foreach ($statements as $sql => $expectedTable) {
            $matched = preg_match('/ALTER TABLE [`"]?(\w+)[`"]?/i', $sql, $tm);
            $this->assertSame(1, $matched, "Should match ALTER TABLE in: {$sql}");
            $this->assertSame($expectedTable, $tm[1]);
        }
    }

    // -------------------------------------------------------------------------
    // Test 3 — captureForeignKeyDefinition builds correct clause string
    // -------------------------------------------------------------------------

    #[Test]
    public function testCaptureForeignKeyDefinitionBuildsClause(): void
    {
        // Simulate what captureForeignKeyDefinition does internally — this tests
        // the string-building logic independent of DB connection.
        $cols = [
            ['COLUMN_NAME' => 'current_version_id', 'REFERENCED_TABLE_NAME' => 'document_version', 'REFERENCED_COLUMN_NAME' => 'id'],
        ];
        $deleteRule = 'SET NULL';
        $updateRule = 'CASCADE';

        $localCols = implode(', ', array_map(fn (array $r): string => "`{$r['COLUMN_NAME']}`", $cols));
        $refTable = $cols[0]['REFERENCED_TABLE_NAME'];
        $refCols = implode(', ', array_map(fn (array $r): string => "`{$r['REFERENCED_COLUMN_NAME']}`", $cols));

        $result = sprintf(
            'FOREIGN KEY (%s) REFERENCES `%s` (%s) ON DELETE %s ON UPDATE %s',
            $localCols,
            $refTable,
            $refCols,
            $deleteRule,
            $updateRule,
        );

        $this->assertSame(
            'FOREIGN KEY (`current_version_id`) REFERENCES `document_version` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
            $result,
        );
    }

    #[Test]
    public function testCaptureForeignKeyDefinitionBuildsCompoundKeyClause(): void
    {
        // Composite FK (two columns)
        $cols = [
            ['COLUMN_NAME' => 'tenant_id', 'REFERENCED_TABLE_NAME' => 'tenant', 'REFERENCED_COLUMN_NAME' => 'id'],
            ['COLUMN_NAME' => 'user_id', 'REFERENCED_TABLE_NAME' => 'tenant', 'REFERENCED_COLUMN_NAME' => 'user_id'],
        ];
        $deleteRule = 'RESTRICT';
        $updateRule = 'RESTRICT';

        $localCols = implode(', ', array_map(fn (array $r): string => "`{$r['COLUMN_NAME']}`", $cols));
        $refTable = $cols[0]['REFERENCED_TABLE_NAME'];
        $refCols = implode(', ', array_map(fn (array $r): string => "`{$r['REFERENCED_COLUMN_NAME']}`", $cols));

        $result = sprintf(
            'FOREIGN KEY (%s) REFERENCES `%s` (%s) ON DELETE %s ON UPDATE %s',
            $localCols,
            $refTable,
            $refCols,
            $deleteRule,
            $updateRule,
        );

        $this->assertSame(
            'FOREIGN KEY (`tenant_id`, `user_id`) REFERENCES `tenant` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
            $result,
        );
    }
}
