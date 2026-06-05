<?php

declare(strict_types=1);

namespace App\Tests\Service\Mcp;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Mcp\CatalogMcpServer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F21 — MCP catalogue server JSON-RPC surface (DB-free, mocked repositories).
 */
#[AllowMockObjectsWithoutExpectations]
final class CatalogMcpServerTest extends TestCase
{
    private function server(?ComplianceFrameworkRepository $fw = null, ?ComplianceRequirementRepository $req = null): CatalogMcpServer
    {
        return new CatalogMcpServer(
            $fw ?? $this->createMock(ComplianceFrameworkRepository::class),
            $req ?? $this->createMock(ComplianceRequirementRepository::class),
        );
    }

    #[Test]
    public function initializeReportsProtocolAndToolCapability(): void
    {
        $res = $this->server()->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);

        self::assertSame(CatalogMcpServer::PROTOCOL_VERSION, $res['result']['protocolVersion']);
        self::assertArrayHasKey('tools', $res['result']['capabilities']);
        self::assertSame(1, $res['id']);
    }

    #[Test]
    public function toolsListAdvertisesTheReadOnlyTools(): void
    {
        $res = $this->server()->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);

        $names = array_column($res['result']['tools'], 'name');
        self::assertContains('list_frameworks', $names);
        self::assertContains('list_requirements', $names);
    }

    #[Test]
    public function toolsCallListFrameworksReturnsTextContent(): void
    {
        $fw = new ComplianceFramework();
        $fw->setCode('EUCS')->setName('EU Cloud Services');

        $repo = $this->createMock(ComplianceFrameworkRepository::class);
        $repo->method('findActiveFrameworks')->willReturn([$fw]);

        $res = $this->server($repo)->handle([
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'list_frameworks', 'arguments' => []],
        ]);

        self::assertStringContainsString('EUCS — EU Cloud Services', $res['result']['content'][0]['text']);
    }

    #[Test]
    public function unknownMethodReturnsJsonRpcError(): void
    {
        $res = $this->server()->handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'does/not/exist']);

        self::assertArrayHasKey('error', $res);
        self::assertSame(4, $res['id']);
    }

    #[Test]
    public function notificationReturnsNoResponse(): void
    {
        $res = $this->server()->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        self::assertNull($res);
    }

    #[Test]
    public function listRequirementsForUnknownFrameworkReportsCleanly(): void
    {
        $repo = $this->createMock(ComplianceFrameworkRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $res = $this->server($repo)->handle([
            'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
            'params' => ['name' => 'list_requirements', 'arguments' => ['framework_code' => 'NOPE']],
        ]);

        self::assertStringContainsString('No framework', $res['result']['content'][0]['text']);
    }
}
