<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DataIntegrity\CrossTenantUserLinkFinder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only: lists records linked to a User / Person of another organisation
 * on a #[SameTenantOwner] relation (legacy data from before the user-picker
 * tenant sweep). Always exits 0 — it reports, it does not judge or repair.
 * See docs/admin/TENANT_SYSTEM.md (upgrade notes).
 */
#[AsCommand(
    name: 'app:data:cross-tenant-user-links',
    description: 'Lists records linked to a user/person of another organisation (read-only report).',
)]
final class CrossTenantUserLinksCommand extends Command
{
    public function __construct(private readonly CrossTenantUserLinkFinder $finder)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = $this->finder->find();

        if ($rows === []) {
            $io->success('No cross-tenant user links found.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Entity', 'ID', 'Field', 'Linked user/person', 'Linked ID', 'Record tenant', 'Linked tenant'],
            array_map(static fn (array $r): array => [
                $r['entity'],
                $r['id'],
                $r['field'],
                $r['linked'],
                $r['linkedId'],
                $r['recordTenant'] ?? '-',
                $r['linkedTenant'] ?? '(none)',
            ], $rows),
        );
        $io->warning(sprintf(
            '%d cross-tenant link(s). They stay intact on save; review and reassign them to a user of the record\'s organisation.',
            count($rows),
        ));

        return Command::SUCCESS;
    }
}
