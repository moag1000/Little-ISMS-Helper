<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Service\PolicyWizard\PolicyAcknowledgementService;
use App\Service\PolicyWizard\PolicyAudienceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Keeps the open acknowledgement requests of the published policies current (ISO 27001
 * A.5.1/A.6.3), nightly from the scheduler:
 *
 *   - a pending row for every audience member without one for the current version — people
 *     who joined later and new versions included (the campaign listener only fires on the
 *     approval itself);
 *   - pending rows nobody can close any more are withdrawn: an older version, a person who
 *     left the audience or was deactivated, a document no longer published or no longer
 *     asking for acknowledgement. Acknowledged rows stay as evidence.
 *
 * The rows feed My Day, the daily reminder digest and the acknowledgement inbox; this command
 * sends no mail itself. Idempotent; `--dry-run` only counts.
 */
#[AsCommand(
    name: 'app:policy-wizard:send-ack-requests',
    description: 'Raise missing and withdraw stale policy acknowledgement requests (nightly)',
)]
final class SendPolicyAcknowledgementRequestsCommand extends Command
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly PolicyAcknowledgementService $acknowledgementService,
        private readonly PolicyAudienceResolver $audienceResolver,
        private readonly PolicyAcknowledgementRepository $acknowledgementRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count open acknowledgements, change nothing.')
            ->setHelp(<<<'HELP'
For every published or approved document that asks for acknowledgement, this command
creates the missing pending requests of its current audience and version, and withdraws
pending requests nobody can close any more (old version, left audience, document no longer
published). It runs nightly from the built-in scheduler; operators never need to call it.

<info>Examples:</info>
  <comment>php bin/console app:policy-wizard:send-ack-requests</comment>
  <comment>php bin/console app:policy-wizard:send-ack-requests --dry-run</comment>
HELP);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('DRY RUN — no changes will be persisted.');
        }

        $io->title('Policy acknowledgement requests');

        /** @var list<Document> $documents */
        $documents = $this->documentRepository->createQueryBuilder('d')
            ->where('d.status IN (:statuses)')
            ->andWhere('d.isArchived = false')
            ->andWhere('d.tenant IS NOT NULL')
            ->setParameter('statuses', ['published', 'approved'])
            ->getQuery()
            ->getResult();

        $rows = [];
        $published = [];
        $totalPending = 0;
        foreach ($documents as $document) {
            $published[(int) $document->getId()] = true;
            if (!PolicyAcknowledgementService::needsAcknowledgement($document)) {
                // Its stale requests (switch turned off) are withdrawn, nobody is asked.
                if (!$dryRun) {
                    $this->acknowledgementService->requestAcknowledgements($document);
                }
                continue;
            }
            $audience = $this->audienceResolver->resolveAudience($document);
            $pending = $dryRun
                ? $this->acknowledgementService->coverageFor($document)['pending']
                : $this->acknowledgementService->requestAcknowledgements($document, $audience);
            $totalPending += $pending;
            $rows[] = [
                $document->getId(),
                $document->getOriginalFilename() ?? $document->getFilename() ?? '',
                count($audience),
                $pending,
            ];
        }

        // Documents that left publication keep no open request.
        $withdrawn = 0;
        foreach ($this->documentsWithPendingRows() as $document) {
            if (!isset($published[(int) $document->getId()]) && !$dryRun) {
                $withdrawn += $this->acknowledgementService->withdrawOpenRequests($document);
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->table(['Document #', 'Title', 'Audience', 'Pending'], $rows);
        $io->success(sprintf(
            '%d document(s) ask for acknowledgement; %d acknowledgement(s) open; %d request(s) of unpublished documents withdrawn.',
            count($rows),
            $totalPending,
            $withdrawn,
        ));

        return Command::SUCCESS;
    }

    /**
     * Instance-wide on purpose: every organisation's documents are swept, each row is handled
     * through its own document (and that document's organisation).
     *
     * @return list<Document>
     */
    private function documentsWithPendingRows(): array
    {
        $ids = $this->acknowledgementRepository->findDocumentIdsWithPendingRows();

        return $ids === [] ? [] : array_values($this->documentRepository->findBy(['id' => $ids]));
    }
}
