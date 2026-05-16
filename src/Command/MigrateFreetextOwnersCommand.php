<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BCExercise;
use App\Entity\InternalAudit;
use App\Entity\Person;
use App\Entity\Training;
use App\Entity\TrainingParticipation;
use App\Entity\User;
use App\Repository\BCExerciseRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\PersonRepository;
use App\Repository\TrainingParticipationRepository;
use App\Repository\TrainingRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * S4 P-15 DataReuse backfill — best-effort migration of free-text owner
 * fields to typed User / Person FKs:
 *
 *   InternalAudit.leadAuditor    → leadAuditorUser | leadAuditorPerson
 *   InternalAudit.auditTeam      → auditTeamMembers (Collection<Person>)
 *   Training.participants        → TrainingParticipation rows
 *   BCExercise.facilitator       → facilitatorUser | facilitatorPerson
 *   BCExercise.participants      → participantPersons (Collection<Person>)
 *   BCExercise.observers         → observerPersons (Collection<Person>)
 *
 * Heuristic: name-match the free-text (case-insensitive substring on
 * `User.fullName` / `Person.fullName`) within the entity's tenant. When
 * exactly one unique match is found in the tenant, the FK is populated;
 * when 0 or >1 candidates match, the row is reported as "needs-review"
 * and left untouched (form-level Pattern-A validation still works because
 * the legacy text remains).
 *
 * Tenant isolation: every lookup is constrained to `entity.tenant`, so
 * cross-tenant string collisions cannot leak ownership.
 *
 * `--dry-run` lists the would-be matches without persisting anything.
 */
#[AsCommand(
    name: 'app:migrate:freetext-owners',
    description: 'Best-effort backfill of legacy free-text owner fields (InternalAudit / Training / BCExercise) to typed User/Person FKs (P-15 DataReuse).',
)]
final class MigrateFreetextOwnersCommand extends Command
{
    public function __construct(
        private readonly InternalAuditRepository $internalAuditRepository,
        private readonly TrainingRepository $trainingRepository,
        private readonly BCExerciseRepository $bcExerciseRepository,
        private readonly UserRepository $userRepository,
        private readonly PersonRepository $personRepository,
        private readonly TrainingParticipationRepository $participationRepository,
        private readonly EntityManagerInterface $entityManager,
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
                'List candidate matches without persisting any change.',
            )
            ->addOption(
                'entity',
                null,
                InputOption::VALUE_REQUIRED,
                'Restrict to a single entity: internal_audit | training | bc_exercise (default = all).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $only = $input->getOption('entity');

        if ($only !== null && !in_array($only, ['internal_audit', 'training', 'bc_exercise'], true)) {
            $io->error(sprintf('Unknown --entity value "%s". Valid: internal_audit, training, bc_exercise.', $only));
            return Command::FAILURE;
        }

        $io->title('P-15 DataReuse backfill — free-text → entity owners');
        if ($dryRun) {
            $io->note('Dry-run: no changes will be persisted.');
        }

        $summary = [
            'internal_audit' => ['matched' => 0, 'needs_review' => 0, 'team_persons_linked' => 0],
            'training' => ['participation_rows_created' => 0, 'users_matched' => 0, 'needs_review' => 0],
            'bc_exercise' => ['facilitator_matched' => 0, 'facilitator_needs_review' => 0, 'participants_linked' => 0, 'observers_linked' => 0],
        ];

        if ($only === null || $only === 'internal_audit') {
            $this->backfillInternalAudits($io, $dryRun, $summary);
        }
        if ($only === null || $only === 'training') {
            $this->backfillTrainings($io, $dryRun, $summary);
        }
        if ($only === null || $only === 'bc_exercise') {
            $this->backfillBcExercises($io, $dryRun, $summary);
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->section('Summary');
        foreach ($summary as $entity => $counts) {
            $io->writeln(sprintf('<info>%s</info>', $entity));
            foreach ($counts as $k => $v) {
                $io->writeln(sprintf('  %s: %d', $k, $v));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, array<string, int>> $summary
     */
    private function backfillInternalAudits(SymfonyStyle $io, bool $dryRun, array &$summary): void
    {
        $io->section('InternalAudit');
        $audits = $this->internalAuditRepository->findAll();
        foreach ($audits as $audit) {
            if (!$audit instanceof InternalAudit) {
                continue;
            }
            $tenant = $audit->getTenant();
            // Lead auditor (only if no Pattern-A FK is set yet)
            if ($audit->getLeadAuditorUser() === null && $audit->getLeadAuditorPerson() === null) {
                $legacy = $audit->getLeadAuditor();
                if ($legacy !== null && trim($legacy) !== '') {
                    $needle = trim($legacy);
                    $matchUser = $this->matchSingleUser($needle, $tenant);
                    $matchPerson = $this->matchSinglePerson($needle, $tenant);
                    if ($matchUser !== null && $matchPerson === null) {
                        $io->writeln(sprintf('  audit #%d leadAuditor "%s" → User #%d', $audit->getId() ?? 0, $needle, $matchUser->getId() ?? 0));
                        if (!$dryRun) {
                            $audit->setLeadAuditorUser($matchUser);
                        }
                        $summary['internal_audit']['matched']++;
                    } elseif ($matchPerson !== null && $matchUser === null) {
                        $io->writeln(sprintf('  audit #%d leadAuditor "%s" → Person #%d', $audit->getId() ?? 0, $needle, $matchPerson->getId() ?? 0));
                        if (!$dryRun) {
                            $audit->setLeadAuditorPerson($matchPerson);
                        }
                        $summary['internal_audit']['matched']++;
                    } else {
                        $io->writeln(sprintf('  <comment>audit #%d leadAuditor "%s" → needs review (matches: user=%s, person=%s)</comment>',
                            $audit->getId() ?? 0,
                            $needle,
                            $matchUser !== null ? '1+' : '0',
                            $matchPerson !== null ? '1+' : '0',
                        ));
                        $summary['internal_audit']['needs_review']++;
                    }
                }
            }
            // Audit team (only if collection still empty)
            if ($audit->getAuditTeamMembers()->isEmpty()) {
                $teamLegacy = $audit->getAuditTeam();
                if ($teamLegacy !== null && trim($teamLegacy) !== '') {
                    $tokens = $this->splitFreetextNames($teamLegacy);
                    foreach ($tokens as $tok) {
                        $p = $this->matchSinglePerson($tok, $tenant);
                        if ($p !== null) {
                            $io->writeln(sprintf('  audit #%d auditTeam "%s" → Person #%d', $audit->getId() ?? 0, $tok, $p->getId() ?? 0));
                            if (!$dryRun) {
                                $audit->addAuditTeamMember($p);
                            }
                            $summary['internal_audit']['team_persons_linked']++;
                        } else {
                            $io->writeln(sprintf('  <comment>audit #%d auditTeam "%s" → no unique Person match</comment>', $audit->getId() ?? 0, $tok));
                            $summary['internal_audit']['needs_review']++;
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array<string, array<string, int>> $summary
     */
    private function backfillTrainings(SymfonyStyle $io, bool $dryRun, array &$summary): void
    {
        $io->section('Training');
        $trainings = $this->trainingRepository->findAll();
        foreach ($trainings as $training) {
            if (!$training instanceof Training) {
                continue;
            }
            $tenant = $training->getTenant();
            $legacy = $training->getParticipants();
            if ($legacy === null || trim($legacy) === '') {
                continue;
            }
            $tokens = $this->splitFreetextNames($legacy);
            foreach ($tokens as $tok) {
                $u = $this->matchSingleUser($tok, $tenant);
                if ($u === null) {
                    $io->writeln(sprintf('  <comment>training #%d participant "%s" → no unique User match</comment>', $training->getId() ?? 0, $tok));
                    $summary['training']['needs_review']++;
                    continue;
                }
                // Idempotent
                $existing = $this->participationRepository->findOneBy([
                    'training' => $training,
                    'user' => $u,
                ]);
                if ($existing instanceof TrainingParticipation) {
                    continue;
                }
                $io->writeln(sprintf('  training #%d participant "%s" → User #%d (new participation)', $training->getId() ?? 0, $tok, $u->getId() ?? 0));
                if (!$dryRun) {
                    $row = new TrainingParticipation();
                    $row->setTenant($tenant);
                    $row->setTraining($training);
                    $row->setUser($u);
                    $row->setStatus(TrainingParticipation::STATUS_PENDING);
                    $row->setAssignmentSource('backfill:freetext_owners');
                    $this->entityManager->persist($row);
                }
                $summary['training']['users_matched']++;
                $summary['training']['participation_rows_created']++;
            }
        }
    }

    /**
     * @param array<string, array<string, int>> $summary
     */
    private function backfillBcExercises(SymfonyStyle $io, bool $dryRun, array &$summary): void
    {
        $io->section('BCExercise');
        $exercises = $this->bcExerciseRepository->findAll();
        foreach ($exercises as $ex) {
            if (!$ex instanceof BCExercise) {
                continue;
            }
            $tenant = $ex->getTenant();

            // facilitator
            if ($ex->getFacilitatorUser() === null && $ex->getFacilitatorPerson() === null) {
                $legacy = $ex->getFacilitator();
                if ($legacy !== null && trim($legacy) !== '') {
                    $needle = trim($legacy);
                    $matchUser = $this->matchSingleUser($needle, $tenant);
                    $matchPerson = $this->matchSinglePerson($needle, $tenant);
                    if ($matchUser !== null && $matchPerson === null) {
                        $io->writeln(sprintf('  bc_exercise #%d facilitator "%s" → User #%d', $ex->getId() ?? 0, $needle, $matchUser->getId() ?? 0));
                        if (!$dryRun) {
                            $ex->setFacilitatorUser($matchUser);
                        }
                        $summary['bc_exercise']['facilitator_matched']++;
                    } elseif ($matchPerson !== null && $matchUser === null) {
                        $io->writeln(sprintf('  bc_exercise #%d facilitator "%s" → Person #%d', $ex->getId() ?? 0, $needle, $matchPerson->getId() ?? 0));
                        if (!$dryRun) {
                            $ex->setFacilitatorPerson($matchPerson);
                        }
                        $summary['bc_exercise']['facilitator_matched']++;
                    } else {
                        $io->writeln(sprintf('  <comment>bc_exercise #%d facilitator "%s" → needs review</comment>', $ex->getId() ?? 0, $needle));
                        $summary['bc_exercise']['facilitator_needs_review']++;
                    }
                }
            }

            // participantPersons
            if ($ex->getParticipantPersons()->isEmpty()) {
                $legacy = $ex->getParticipants();
                if ($legacy !== null && trim($legacy) !== '') {
                    foreach ($this->splitFreetextNames($legacy) as $tok) {
                        $p = $this->matchSinglePerson($tok, $tenant);
                        if ($p !== null) {
                            $io->writeln(sprintf('  bc_exercise #%d participant "%s" → Person #%d', $ex->getId() ?? 0, $tok, $p->getId() ?? 0));
                            if (!$dryRun) {
                                $ex->addParticipantPerson($p);
                            }
                            $summary['bc_exercise']['participants_linked']++;
                        }
                    }
                }
            }

            // observerPersons
            if ($ex->getObserverPersons()->isEmpty()) {
                $legacy = $ex->getObservers();
                if ($legacy !== null && trim($legacy) !== '') {
                    foreach ($this->splitFreetextNames($legacy) as $tok) {
                        $p = $this->matchSinglePerson($tok, $tenant);
                        if ($p !== null) {
                            $io->writeln(sprintf('  bc_exercise #%d observer "%s" → Person #%d', $ex->getId() ?? 0, $tok, $p->getId() ?? 0));
                            if (!$dryRun) {
                                $ex->addObserverPerson($p);
                            }
                            $summary['bc_exercise']['observers_linked']++;
                        }
                    }
                }
            }
        }
    }

    /**
     * Match exactly one User by case-insensitive name substring inside a
     * tenant. Returns null when 0 or >1 candidates match.
     */
    private function matchSingleUser(string $needle, ?object $tenant): ?User
    {
        if ($tenant === null) {
            return null;
        }
        $qb = $this->userRepository->createQueryBuilder('u')
            ->andWhere('u.tenant = :tenant')
            ->andWhere('LOWER(u.fullName) LIKE :n')
            ->setParameter('tenant', $tenant)
            ->setParameter('n', '%' . mb_strtolower($needle) . '%')
            ->setMaxResults(2);
        $matches = $qb->getQuery()->getResult();
        if (is_array($matches) && count($matches) === 1 && $matches[0] instanceof User) {
            return $matches[0];
        }
        return null;
    }

    /**
     * Match exactly one Person by case-insensitive name substring inside a
     * tenant. Returns null when 0 or >1 candidates match.
     */
    private function matchSinglePerson(string $needle, ?object $tenant): ?Person
    {
        if ($tenant === null) {
            return null;
        }
        $qb = $this->personRepository->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('LOWER(p.fullName) LIKE :n')
            ->setParameter('tenant', $tenant)
            ->setParameter('n', '%' . mb_strtolower($needle) . '%')
            ->setMaxResults(2);
        $matches = $qb->getQuery()->getResult();
        if (is_array($matches) && count($matches) === 1 && $matches[0] instanceof Person) {
            return $matches[0];
        }
        return null;
    }

    /**
     * Split a free-text comma/semicolon/newline-separated list into
     * individual trimmed tokens. Empty tokens dropped.
     *
     * @return list<string>
     */
    private function splitFreetextNames(string $blob): array
    {
        $parts = preg_split('/[,;\r\n]+/u', $blob) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }
}
