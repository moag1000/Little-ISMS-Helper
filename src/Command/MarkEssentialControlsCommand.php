<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ControlRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Marks ISO 27001 Annex A controls as "essential for small business" based on
 * the Generic Starter baseline (BL-GENERIC-v1) control selection.
 *
 * These ~31 controls represent the minimum viable control set for
 * organisations with fewer than 50 FTE.
 *
 * Idempotent: safe to run multiple times. Sets essentialForSmallBusiness=true
 * on matching controls and false on all others within each tenant.
 */
#[AsCommand(
    name: 'app:mark-essential-controls',
    description: 'Mark the ~31 Generic Starter baseline controls as essential for small business (KMU/SME).',
)]
final class MarkEssentialControlsCommand
{
    /**
     * Control IDs from the Generic Starter baseline (BL-GENERIC-v1)
     * plus additional controls recommended for SME by the user spec.
     *
     * Source: LoadIndustryBaselinesCommand::genericStarterBaseline()
     * preset_applicable_controls, extended with commonly recommended
     * controls for SME (A.5.29, A.5.36, A.6.1, A.7.2, A.8.9, A.8.11,
     * A.8.16, A.8.20, A.8.24, A.8.25, A.8.32).
     *
     * @var list<string>
     */
    private const ESSENTIAL_CONTROL_IDS = [
        // Organizational controls
        'A.5.1',   // Policies for information security
        'A.5.2',   // Information security roles and responsibilities
        'A.5.10',  // Acceptable use of information and other associated assets
        'A.5.15',  // Access control
        'A.5.17',  // Authentication information
        'A.5.23',  // Information security for use of cloud services
        'A.5.24',  // Information security incident management planning and preparation
        'A.5.29',  // Information security during disruption (BCM/ICT)
        'A.5.36',  // Compliance with policies, rules and standards

        // People controls
        'A.6.1',   // Screening
        'A.6.3',   // Information security awareness, education and training
        'A.6.5',   // Responsibilities after termination or change of employment

        // Physical controls
        'A.7.1',   // Physical security perimeters
        'A.7.2',   // Physical entry

        // Technological controls
        'A.8.1',   // User endpoint devices
        'A.8.2',   // Privileged access rights
        'A.8.5',   // Secure authentication
        'A.8.7',   // Protection against malware
        'A.8.8',   // Management of technical vulnerabilities
        'A.8.9',   // Configuration management
        'A.8.11',  // Data masking
        'A.8.12',  // Data leakage prevention
        'A.8.13',  // Information backup
        'A.8.15',  // Logging
        'A.8.16',  // Monitoring activities
        'A.8.20',  // Networks security
        'A.8.23',  // Web filtering
        'A.8.24',  // Use of cryptography
        'A.8.25',  // Secure development life cycle
        'A.8.28',  // Secure coding
        'A.8.32',  // Change management
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ControlRepository $controlRepository,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->title('Marking essential controls for small business (KMU/SME)');

        $allControls = $this->controlRepository->findAll();

        if ($allControls === []) {
            $io->warning('No controls found in the database. Load Annex A controls first.');
            return Command::SUCCESS;
        }

        $marked = 0;
        $unmarked = 0;

        foreach ($allControls as $control) {
            $controlId = $control->getControlId();
            $isEssential = in_array($controlId, self::ESSENTIAL_CONTROL_IDS, true);

            if ($control->isEssentialForSmallBusiness() !== $isEssential) {
                $control->setEssentialForSmallBusiness($isEssential);
                if ($isEssential) {
                    $marked++;
                } else {
                    $unmarked++;
                }
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Done. %d control(s) marked as essential, %d control(s) unmarked. '
            . '(%d essential control IDs configured, %d total controls in DB.)',
            $marked,
            $unmarked,
            count(self::ESSENTIAL_CONTROL_IDS),
            count($allControls),
        ));

        return Command::SUCCESS;
    }
}
