<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Asset;
use App\Entity\BusinessProcess;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:load-connected-demo-data',
    description: 'Load a connected demo scenario (Process → Asset → Risk) to demonstrate the data model relationships'
)]
class LoadConnectedDemoDataCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        #[Option(name: 'update', shortcut: 'u', description: 'Update existing demo entities instead of skipping them')]
        bool $update = false,
        ?SymfonyStyle $symfonyStyle = null
    ): int {
        $symfonyStyle->title('Loading Connected Demo Scenario');
        $symfonyStyle->text(sprintf('Mode: %s', $update ? 'UPDATE existing' : 'CREATE new (skip existing)'));

        // Find first tenant
        $tenant = $this->entityManager->getRepository(Tenant::class)->findOneBy([]);
        if (!$tenant instanceof Tenant) {
            $symfonyStyle->error('No tenant found. Please create a tenant first.');
            return Command::FAILURE;
        }
        $symfonyStyle->text(sprintf('Using tenant: %s', $tenant->getName()));

        // Find admin user for risk ownership
        $adminUser = $this->findAdminUser();
        if (!$adminUser instanceof User) {
            $symfonyStyle->error('No admin user found. Please create an admin user first.');
            return Command::FAILURE;
        }
        $symfonyStyle->text(sprintf('Using admin user: %s', $adminUser->getEmail()));

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        // 1. Create Assets
        $assetErp = $this->createOrUpdateAsset(
            $tenant,
            'ERP-System (SAP)',
            'application',
            'Zentrales ERP-System fuer Finanzbuchhaltung, Controlling und Materialwirtschaft. Enthaelt alle geschaeftskritischen Finanzdaten.',
            4, // confidentiality
            4, // integrity
            5, // availability
            'confidential',
            $update,
            $stats,
            $symfonyStyle
        );

        $assetMail = $this->createOrUpdateAsset(
            $tenant,
            'Mailserver (Exchange)',
            'application',
            'Zentraler E-Mail-Server fuer die gesamte Unternehmenskommunikation. Verarbeitet vertrauliche Geschaeftskorrespondenz.',
            3, // confidentiality
            3, // integrity
            4, // availability
            'internal',
            $update,
            $stats,
            $symfonyStyle
        );

        // 2. Create Business Process
        $process = $this->createOrUpdateBusinessProcess(
            $tenant,
            'Buchhaltung & Finanzen',
            'Kernprozess fuer Finanzbuchhaltung, Rechnungsstellung, Mahnwesen und Jahresabschluss. Abhaengig vom ERP-System.',
            'critical',
            4, // RTO in hours
            1, // RPO in hours
            $update,
            $stats,
            $symfonyStyle
        );

        // Link asset to process
        if ($process instanceof BusinessProcess && $assetErp instanceof Asset) {
            if (!$process->getSupportingAssets()->contains($assetErp)) {
                $process->addSupportingAsset($assetErp);
                $symfonyStyle->text('  Linked Asset "ERP-System (SAP)" to Process "Buchhaltung & Finanzen"');
            }
        }

        // 3. Create Risks linked to Assets
        $risk1 = $this->createOrUpdateRisk(
            $tenant,
            $adminUser,
            'Unbefugter Zugriff auf Finanzdaten',
            'Ein Mitarbeiter oder externer Angreifer erhaelt unbefugten Zugriff auf vertrauliche Finanzdaten im ERP-System. Dies kann zu Datenlecks, Manipulation von Buchungen oder Compliance-Verstoessen fuehren.',
            'Insider Threat',
            'Unzureichende Zugriffskontrollen und fehlende Protokollierung privilegierter Zugriffe',
            3, // probability
            4, // impact
            'mitigate',
            $assetErp,
            'security',
            $update,
            $stats,
            $symfonyStyle
        );

        $risk2 = $this->createOrUpdateRisk(
            $tenant,
            $adminUser,
            'Ransomware-Angriff auf Mailserver',
            'Ueber eine Phishing-E-Mail wird Ransomware eingeschleust, die den Mailserver verschluesselt. Die gesamte E-Mail-Kommunikation faellt aus, vertrauliche Daten koennten exfiltriert werden.',
            'Schadprogramme (G 0.39)',
            'Fehlende E-Mail-Filterung und unzureichendes Mitarbeiter-Awareness-Training',
            3, // probability
            5, // impact
            'mitigate',
            $assetMail,
            'operational',
            $update,
            $stats,
            $symfonyStyle
        );

        // Link risks to business process
        if ($process instanceof BusinessProcess) {
            if ($risk1 instanceof Risk && !$process->getIdentifiedRisks()->contains($risk1)) {
                $process->addIdentifiedRisk($risk1);
                $symfonyStyle->text('  Linked Risk "Unbefugter Zugriff" to Process "Buchhaltung & Finanzen"');
            }
            if ($risk2 instanceof Risk && !$process->getIdentifiedRisks()->contains($risk2)) {
                $process->addIdentifiedRisk($risk2);
                $symfonyStyle->text('  Linked Risk "Ransomware-Angriff" to Process "Buchhaltung & Finanzen"');
            }
        }

        $this->entityManager->flush();

        $symfonyStyle->newLine();
        $symfonyStyle->success('Connected demo scenario loaded!');
        $symfonyStyle->table(
            ['Action', 'Count'],
            [
                ['Created', $stats['created']],
                ['Updated', $stats['updated']],
                ['Skipped', $stats['skipped']],
            ]
        );

        $symfonyStyle->section('Data Model Connections');
        $symfonyStyle->listing([
            'Process "Buchhaltung & Finanzen" → Asset "ERP-System (SAP)" → Risk "Unbefugter Zugriff auf Finanzdaten"',
            'Process "Buchhaltung & Finanzen" → Asset "Mailserver (Exchange)" → Risk "Ransomware-Angriff auf Mailserver"',
        ]);

        return Command::SUCCESS;
    }

    private function findAdminUser(): ?User
    {
        $users = $this->entityManager->getRepository(User::class)->findAll();
        foreach ($users as $user) {
            $roles = $user->getRoles();
            if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_SUPER_ADMIN', $roles, true)) {
                return $user;
            }
        }
        // Fallback: return the first user
        return $users[0] ?? null;
    }

    private function createOrUpdateAsset(
        Tenant $tenant,
        string $name,
        string $assetType,
        string $description,
        int $confidentiality,
        int $integrity,
        int $availability,
        string $dataClassification,
        bool $update,
        array &$stats,
        SymfonyStyle $io
    ): ?Asset {
        $existing = $this->entityManager->getRepository(Asset::class)
            ->findOneBy(['name' => $name, 'tenant' => $tenant]);

        if ($existing instanceof Asset) {
            if ($update) {
                $existing->setAssetType($assetType)
                    ->setDescription($description)
                    ->setConfidentialityValue($confidentiality)
                    ->setIntegrityValue($integrity)
                    ->setAvailabilityValue($availability)
                    ->setDataClassification($dataClassification)
                    ->setStatus('active')
                    ->setUpdatedAt(new DateTimeImmutable());
                $stats['updated']++;
                $io->text(sprintf('  Updated Asset: %s', $name));
                return $existing;
            }
            $stats['skipped']++;
            $io->text(sprintf('  Skipped Asset: %s (already exists)', $name));
            return $existing;
        }

        $asset = new Asset();
        $asset->setTenant($tenant)
            ->setName($name)
            ->setAssetType($assetType)
            ->setDescription($description)
            ->setOwner('IT-Abteilung')
            ->setConfidentialityValue($confidentiality)
            ->setIntegrityValue($integrity)
            ->setAvailabilityValue($availability)
            ->setDataClassification($dataClassification)
            ->setStatus('active');

        $this->entityManager->persist($asset);
        $stats['created']++;
        $io->text(sprintf('  Created Asset: %s', $name));

        return $asset;
    }

    private function createOrUpdateBusinessProcess(
        Tenant $tenant,
        string $name,
        string $description,
        string $criticality,
        int $rto,
        int $rpo,
        bool $update,
        array &$stats,
        SymfonyStyle $io
    ): ?BusinessProcess {
        $existing = $this->entityManager->getRepository(BusinessProcess::class)
            ->findOneBy(['name' => $name, 'tenant' => $tenant]);

        if ($existing instanceof BusinessProcess) {
            if ($update) {
                $existing->setDescription($description)
                    ->setCriticality($criticality)
                    ->setRto($rto)
                    ->setRpo($rpo)
                    ->setUpdatedAt(new DateTimeImmutable());
                $stats['updated']++;
                $io->text(sprintf('  Updated BusinessProcess: %s', $name));
                return $existing;
            }
            $stats['skipped']++;
            $io->text(sprintf('  Skipped BusinessProcess: %s (already exists)', $name));
            return $existing;
        }

        $process = new BusinessProcess();
        $process->setTenant($tenant)
            ->setName($name)
            ->setDescription($description)
            ->setProcessOwner('Leiter Finanzen')
            ->setCriticality($criticality)
            ->setRto($rto)
            ->setRpo($rpo)
            ->setMtpd(24)
            ->setReputationalImpact(4)
            ->setRegulatoryImpact(5)
            ->setOperationalImpact(4)
            ->setRecoveryStrategy('Failover auf Backup-System, manuelle Buchung als Notfallprozess');

        $this->entityManager->persist($process);
        $stats['created']++;
        $io->text(sprintf('  Created BusinessProcess: %s', $name));

        return $process;
    }

    private function createOrUpdateRisk(
        Tenant $tenant,
        User $riskOwner,
        string $title,
        string $description,
        string $threat,
        string $vulnerability,
        int $probability,
        int $impact,
        string $treatmentStrategy,
        ?Asset $asset,
        string $category,
        bool $update,
        array &$stats,
        SymfonyStyle $io
    ): ?Risk {
        $existing = $this->entityManager->getRepository(Risk::class)
            ->findOneBy(['title' => $title, 'tenant' => $tenant]);

        if ($existing instanceof Risk) {
            if ($update) {
                $existing->setDescription($description)
                    ->setThreat($threat)
                    ->setVulnerability($vulnerability)
                    ->setProbability($probability)
                    ->setImpact($impact)
                    ->setTreatmentStrategy($treatmentStrategy)
                    ->setAsset($asset)
                    ->setRiskOwner($riskOwner)
                    ->setCategory($category);
                $stats['updated']++;
                $io->text(sprintf('  Updated Risk: %s', $title));
                return $existing;
            }
            $stats['skipped']++;
            $io->text(sprintf('  Skipped Risk: %s (already exists)', $title));
            return $existing;
        }

        $risk = new Risk();
        $risk->setTenant($tenant)
            ->setTitle($title)
            ->setDescription($description)
            ->setThreat($threat)
            ->setVulnerability($vulnerability)
            ->setProbability($probability)
            ->setImpact($impact)
            ->setResidualProbability(2)
            ->setResidualImpact($impact > 1 ? $impact - 1 : 1)
            ->setTreatmentStrategy($treatmentStrategy)
            ->setTreatmentDescription('Massnahmen werden im Risikobehandlungsplan definiert')
            ->setAsset($asset)
            ->setRiskOwner($riskOwner)
            ->setCategory($category)
            ->setStatus('assessed')
            ->setReviewDate(new \DateTime('+90 days'));

        $this->entityManager->persist($risk);
        $stats['created']++;
        $io->text(sprintf('  Created Risk: %s', $title));

        return $risk;
    }
}
