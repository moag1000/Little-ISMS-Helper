<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\DocumentGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill `Document.policyBody` for legacy wizard-generated documents
 * created before migration `20260510010000_document_policy_body` added
 * the column. Without this backfill, those rows show empty bodies on
 * `/document/{id}` (the show-template hides the preview block when
 * `getEffectivePolicyBody()` resolves to null) and the PDF exporter
 * falls back to the `_(empty body)_` stub.
 *
 * Re-renders the body deterministically from the persisted
 * PolicyTemplate + substitutionVariables, so the result is identical
 * to what a fresh wizard run would have produced — no manual edits
 * are inferred or invented.
 */
#[AsCommand(
    name: 'app:policy-wizard:backfill-policy-bodies',
    description: 'Re-render and persist Document.policyBody for legacy wizard-generated docs (pre-W7-X migration).',
)]
final class BackfillPolicyBodyCommand extends Command
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentGenerator $documentGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'List candidate docs without persisting any changes.',
            )
            ->addOption(
                'tenant-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Restrict backfill to a single tenant id.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $tenantId = $input->getOption('tenant-id');

        $criteria = ['policyBody' => null];
        if ($tenantId !== null) {
            $criteria['tenant'] = (int) $tenantId;
        }

        $candidates = $this->documentRepository->findBy($criteria);
        $candidates = array_filter(
            $candidates,
            static fn (Document $d): bool => $d->getGeneratedFromTemplate() !== null,
        );

        if ($candidates === []) {
            $io->success('No wizard-generated documents with empty policyBody. Nothing to do.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Backfill candidates: %d', count($candidates)));

        $filled = 0;
        $skipped = 0;
        foreach ($candidates as $doc) {
            $body = $this->documentGenerator->renderBodyForBackfill($doc);
            if ($body === null) {
                $io->writeln(sprintf(
                    '  <comment>SKIP</comment> #%d %s — no template body resolvable',
                    $doc->getId() ?? 0,
                    $doc->getOriginalFilename() ?? '',
                ));
                ++$skipped;
                continue;
            }

            $io->writeln(sprintf(
                '  <info>FILL</info> #%d %s (%d chars)',
                $doc->getId() ?? 0,
                $doc->getOriginalFilename() ?? '',
                strlen($body),
            ));

            if (!$dryRun) {
                $doc->setPolicyBody($body);
                $this->entityManager->persist($doc);
                $this->auditLogger?->logCustom(
                    'policy_wizard.policy_body_backfilled',
                    'Document',
                    $doc->getId(),
                    [
                        'document_id' => $doc->getId(),
                        'tenant_id' => $doc->getTenant()?->getId(),
                        'body_length' => strlen($body),
                    ],
                );
            }

            ++$filled;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            '%s — filled: %d, skipped: %d',
            $dryRun ? 'DRY-RUN' : 'COMMITTED',
            $filled,
            $skipped,
        ));

        return Command::SUCCESS;
    }
}
