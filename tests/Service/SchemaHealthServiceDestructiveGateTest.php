<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AuditLogger;
use App\Service\SchemaHealthService;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * applyUpdate() must NOT execute destructive statements unless allowDestructive
 * is set. We subclass to stub the SQL the tool would emit, so no live DB is hit.
 */
class SchemaHealthServiceDestructiveGateTest extends TestCase
{
    private function makeService(array $stubSql, array &$executed): SchemaHealthService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $conn = $this->createMock(Connection::class);
        $em->method('getConnection')->willReturn($conn);
        $mf = $this->createMock(ClassMetadataFactory::class);
        $mf->method('getAllMetadata')->willReturn([]);
        $em->method('getMetadataFactory')->willReturn($mf);
        $conn->method('executeStatement')->willReturnCallback(
            function (string $sql) use (&$executed): int {
                $executed[] = $sql;
                return 0;
            },
        );
        $conn->method('fetchOne')->willReturn('0'); // FK checks already off

        $df = $this->createMock(DependencyFactory::class);
        $audit = $this->createMock(AuditLogger::class);

        return new class($em, $audit, $df, $stubSql) extends SchemaHealthService {
            /** @param list<string> $stub */
            public function __construct($em, $audit, $df, private array $stub)
            {
                parent::__construct($em, $audit, $df);
            }
            protected function pendingMigrationVersions(): array { return []; }
            protected function computeUpdateSql(): array { return $this->stub; }
        };
    }

    #[Test]
    public function skipsDestructiveByDefault(): void
    {
        $executed = [];
        $service = $this->makeService(
            ['ALTER TABLE a ADD COLUMN x INT', 'ALTER TABLE a DROP COLUMN y'],
            $executed,
        );

        $result = $service->applyUpdate('test', bypassMigrationGate: true);

        self::assertTrue($result['success']);
        self::assertContains('ALTER TABLE a ADD COLUMN x INT', $executed);
        self::assertNotContains('ALTER TABLE a DROP COLUMN y', $executed);
        self::assertSame(['ALTER TABLE a DROP COLUMN y'], $result['skipped_destructive']);
    }

    #[Test]
    public function executesDestructiveWhenAllowed(): void
    {
        $executed = [];
        $service = $this->makeService(
            ['ALTER TABLE a DROP COLUMN y'],
            $executed,
        );

        $result = $service->applyUpdate('test', bypassMigrationGate: true, allowDestructive: true);

        self::assertTrue($result['success']);
        self::assertContains('ALTER TABLE a DROP COLUMN y', $executed);
        self::assertSame([], $result['skipped_destructive']);
    }
}
