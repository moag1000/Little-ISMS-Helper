<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\FrameworkModuleValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * L-02: Warn about active frameworks whose required modules are disabled.
 * Return 1 on any issue, 0 when all frameworks are operational.
 */
#[AsCommand(
    name: 'app:compliance:validate-framework-modules',
    description: 'Warn if an active ComplianceFramework has disabled required modules'
)]
class ValidateFrameworkModulesCommand
{
    public function __construct(private readonly FrameworkModuleValidator $validator)
    {
    }

    public function __invoke(SymfonyStyle $symfonyStyle): int
    {
        $issues = $this->validator->findFrameworksWithMissingModules();
        if ($issues === []) {
            $symfonyStyle->success('All active frameworks have their required modules enabled.');
            return Command::SUCCESS;
        }
        $symfonyStyle->warning(sprintf('%d framework(s) reference disabled modules:', count($issues)));
        $symfonyStyle->table(
            ['Framework', 'Missing modules'],
            array_map(
                static fn(array $i): array => [
                    $i['framework_code'] . ' — ' . $i['framework_name'],
                    implode(', ', $i['missing_modules']),
                ],
                $issues
            )
        );
        return Command::FAILURE;
    }
}
