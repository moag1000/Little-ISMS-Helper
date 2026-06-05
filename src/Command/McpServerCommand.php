<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Mcp\CatalogMcpServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * F21 — MCP (Model Context Protocol) stdio server for read-only catalogue
 * queries. Speaks JSON-RPC 2.0 line-delimited over STDIN/STDOUT, the transport
 * an MCP host (LLM client) expects. Each STDIN line is one JSON-RPC request;
 * each response is written as a single JSON line to STDOUT.
 *
 * Usage (MCP host config): command = `php bin/console app:mcp-server`.
 */
#[AsCommand(
    name: 'app:mcp-server',
    description: 'Run the read-only compliance-catalogue MCP server over stdio (F21).',
)]
final class McpServerCommand extends Command
{
    public function __construct(
        private readonly CatalogMcpServer $server,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stdin = fopen('php://stdin', 'rb');
        if ($stdin === false) {
            return Command::FAILURE;
        }

        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                /** @var array<string, mixed> $request */
                $request = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->writeLine($output, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
                continue;
            }

            $response = $this->server->handle($request);
            if ($response !== null) {
                $this->writeLine($output, $response);
            }
        }

        fclose($stdin);

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeLine(OutputInterface $output, array $payload): void
    {
        $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
