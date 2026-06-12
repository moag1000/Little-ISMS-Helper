<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SchemaHealthService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SchemaHealthServiceDriftClassifierTest extends TestCase
{
    #[Test]
    public function classifiesAdditiveAndDestructiveStatements(): void
    {
        $sql = [
            'ALTER TABLE asset ADD COLUMN foo VARCHAR(255) DEFAULT NULL',
            'CREATE TABLE bar (id INT)',
            'ALTER TABLE asset DROP COLUMN legacy',
            'DROP TABLE obsolete',
            '-- ERROR: something',
        ];

        $result = SchemaHealthService::classifyStatements($sql);

        self::assertSame(
            [
                'ALTER TABLE asset ADD COLUMN foo VARCHAR(255) DEFAULT NULL',
                'CREATE TABLE bar (id INT)',
            ],
            $result['additive'],
        );
        self::assertSame(
            [
                'ALTER TABLE asset DROP COLUMN legacy',
                'DROP TABLE obsolete',
            ],
            $result['destructive'],
        );
        self::assertSame(['-- ERROR: something'], $result['errors']);
    }

    #[Test]
    public function nonDestructiveAltersAreNotFlagged(): void
    {
        // RENAME / MODIFY / ADD INDEX must NOT be classified destructive — the
        // gate is a safety boundary, so over-flagging would withhold safe DDL.
        $sql = [
            'ALTER TABLE asset RENAME TO asset_v2',
            'ALTER TABLE asset MODIFY foo VARCHAR(512) DEFAULT NULL',
            'alter table asset add index idx_foo (foo)',
        ];

        $result = SchemaHealthService::classifyStatements($sql);

        self::assertSame($sql, $result['additive']);
        self::assertSame([], $result['destructive']);
    }
}
