<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use App\Service\PolicyWizard\PolicyAcknowledgementService;
use App\Service\PolicyWizard\PolicyAudienceResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Walks all published policy documents and records the per-user
 * audience snapshot, raising "pending" obligations for users that have
 * not acknowledged yet.
 *
 * Idempotent — the underlying service skips users that already
 * acknowledged the current document version. Safe to run on a tight
 * cron interval (e.g. every 4 hours).
 *
 * Closes the auditor's predicted ISO 27001 A.6.3 NC by ensuring that no
 * published policy "stalls" without anyone being asked to acknowledge.
 */
#[AsCommand(
    name: 'app:policy-wizard:send-ack-requests',
    description: 'Compute pending Policy-Wizard acknowledgement obligations for all published policies (W3-L)',
)]
final class SendPolicyAcknowledgementRequestsCommand extends Command
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly PolicyAcknowledgementService $acknowledgementService,
        private readonly PolicyAudienceResolver $audienceResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compute pending counts without raising obligations.')
            ->setHelp(<<<'HELP'
This command iterates all published policy documents and computes the
pending acknowledgement count per document. It is idempotent — users
who already acknowledged the current document version are skipped.

<info>Examples:</info>
  # Production cron (every 4 hours)
  <comment>php bin/console app:policy-wizard:send-ack-requests</comment>

  # Inspect what would happen — no side effects
  <comment>php bin/console app:policy-wizard:send-ack-requests --dry-run</comment>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('DRY RUN — no changes will be persisted.');
        }

        $io->title('Policy-Wizard — Acknowledgement Request Sweep');

        $documents = $this->documentRepository->createQueryBuilder('d')
            ->where('d.status IN (:statuses)')
            ->andWhere('d.isArchived = false')
            ->setParameter('statuses', ['published', 'approved'])
            ->getQuery()
            ->getResult();

        if (!is_array($documents) || $documents === []) {
            $io->success('No published documents found — nothing to do.');
            return Command::SUCCESS;
        }

        $rows = [];
        $processed = 0;
        $totalPending = 0;
        foreach ($documents as $document) {
            if (!$document instanceof Document) {
                continue;
            }
            $audience = $this->audienceResolver->resolveAudience($document);
            if ($dryRun) {
                $coverage = $this->acknowledgementService->coverageFor($document);
                $pending = $coverage['pending'];
            } else {
                $pending = $this->acknowledgementService->requestAcknowledgements($document, $audience);
            }
            $processed++;
            $totalPending += $pending;
            $rows[] = [
                $document->getId(),
                $document->getOriginalFilename() ?? $document->getFilename() ?? '',
                count($audience),
                $pending,
            ];
        }

        $io->table(
            ['Document #', 'Title', 'Audience', 'Pending'],
            $rows,
        );

        $io->success(sprintf(
            'Processed %d document(s); %d total pending acknowledgement obligation(s).',
            $processed,
            $totalPending,
        ));

        return Command::SUCCESS;
    }
}
