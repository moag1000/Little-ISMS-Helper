<?php

declare(strict_types=1);

namespace App\Command;

use App\Controller\DeploymentWizardController;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Local-only benchmark for the wizard's runFreshSchemaInstall().
 *
 * Reflects into the controller's private method so we measure exactly the
 * code that the Step-3-Skip route runs in production. Drops + recreates the
 * full schema, prints the per-phase timings.
 *
 * USAGE: php bin/console app:bench-schema-install --env=test
 *
 * WARNING: destroys all rows in the active database. NEVER run against prod.
 */
#[AsCommand(name: 'app:bench-schema-install', description: 'Benchmark wizard schema-install timings (DESTRUCTIVE).')]
final class BenchSchemaInstallCommand extends Command
{
    public function __construct(private readonly DeploymentWizardController $controller)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('runFreshSchemaInstall');

        $output->writeln('<info>Running runFreshSchemaInstall() against the active database…</info>');
        $t0 = microtime(true);
        $result = $method->invoke($this->controller);
        $wall = (microtime(true) - $t0) * 1000;

        $output->writeln('');
        $output->writeln(sprintf('Wall time: <info>%d ms</info>', (int) round($wall)));
        $output->writeln('Success:   ' . ($result['success'] ? '<info>true</info>' : '<error>false</error>'));
        $output->writeln('Message:   ' . ($result['message'] ?? '-'));
        if (!empty($result['output'])) {
            $output->writeln('Output:    ' . $result['output']);
        }

        if (!empty($result['timings'])) {
            $output->writeln('');
            $output->writeln('<comment>Per-phase timings:</comment>');
            foreach ($result['timings'] as $key => $value) {
                $output->writeln(sprintf('  %-26s %s', $key . ':', $value));
            }
        }

        return $result['success'] ? Command::SUCCESS : Command::FAILURE;
    }
}
