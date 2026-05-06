<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * NIS-2-Umsetzungs- und Cybersicherheitsstaerkungsgesetz (NIS2UmsuCG)
 * full §§-catalogue. Source: BGBl. 2025 I Nr. 301 (NIS2UmsuCG-Stamm) +
 * BSI-Mindestanforderungen-Konsultation 2024-12.
 */
#[AsCommand(
    name: 'app:load-nis2-umsucg-full',
    description: 'Load NIS-2-Umsetzungs- und Cybersicherheitsstaerkungsgesetz (BGBl. 2025 I Nr. 301) §§ as ComplianceRequirement rows.'
)]
final class LoadNis2UmsuCGFullCommand extends Command
{
    /** @var array<string, string> */
    private const PARAGRAPHS = [
        // Teil 1 — Allgemeines
        '§1'  => 'Anwendungsbereich (BSIG)',
        '§2'  => 'Begriffsbestimmungen',
        '§3'  => 'Bundesamt fuer Sicherheit in der Informationstechnik (BSI)',
        // Teil 2 — Aufgaben + Befugnisse des BSI
        '§4'  => 'Aufgaben des Bundesamtes',
        '§5'  => 'Pruefung von Schwachstellen',
        '§6'  => 'Untersuchung der Sicherheit in der Informationstechnik',
        '§7'  => 'Umgang mit Schwachstellen, Schadprogrammen und Sicherheitsrisiken',
        '§8'  => 'Untersagung des Einsatzes kritischer Komponenten',
        // Teil 3 — Besonders wichtige Einrichtungen + wichtige Einrichtungen
        '§28' => 'Besonders wichtige Einrichtungen + wichtige Einrichtungen — Bestimmung',
        '§29' => 'Registrierungspflicht',
        '§30' => 'Risikomanagementmassnahmen',
        '§31' => 'Massnahmen bei besonderem Bundesinteresse (Top-Mgmt-Pflichten)',
        '§32' => 'Meldepflichten (24h Fruehwarnung / 72h Meldung / 1-Monat Abschlussbericht)',
        '§33' => 'Unterrichtungspflichten',
        '§34' => 'Billigung der Risikomgmt-Massnahmen + Schulung der Geschaeftsleitung',
        '§35' => 'Pflicht zur Beauftragung von externen Pruefern (zertifizierte Auditoren)',
        // Teil 4 — Kritische Anlagen
        '§39' => 'Kritische Anlagen — Bestimmung',
        '§40' => 'Registrierungspflicht von Betreibern kritischer Anlagen',
        '§41' => 'Besondere Pflichten der Betreiber kritischer Anlagen (Stand der Technik, B3S)',
        '§42' => 'Nachweispflichten (alle 2 Jahre Audits gegenueber BSI)',
        '§43' => 'Meldepflichten Betreiber kritischer Anlagen',
        // Teil 5 — Sektorspezifische Regelungen
        '§44' => 'Sektor Energie',
        '§45' => 'Sektor Verkehr',
        '§46' => 'Sektor Gesundheit',
        '§47' => 'Sektor Wasser + Abwasser',
        '§48' => 'Sektor Banken-, Versicherungs- und sonstige Finanzdienstleistungen',
        '§49' => 'Sektor Informations- und Telekommunikationstechnik',
        '§50' => 'Sektor oeffentliche Verwaltung',
        // Teil 6 — Aufsicht + Durchsetzung
        '§60' => 'Aufsicht',
        '§61' => 'Anordnungsbefugnis',
        '§62' => 'Anordnung von Ueberpruefungen',
        '§63' => 'Auskunftsverlangen',
        '§64' => 'Befugnisse zum Betreten von Geschaeftsraeumen',
        '§65' => 'Vorlage von Dokumenten und Auswertungen',
        // Teil 7 — Bussgeldvorschriften
        '§66' => 'Bussgeldvorschriften (max. 10 Mio EUR oder 2% Konzern-Jahresumsatz)',
        '§67' => 'Verwaltungsvollstreckung',
        // Teil 8 — Schlussvorschriften
        '§68' => 'Verordnungsermaechtigungen',
        '§69' => 'Uebergangsregelungen',
        '§70' => 'Inkrafttreten',
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $framework = $this->frameworkRepository->findOneBy(['code' => 'NIS2-UmsuCG'])
            ?? $this->frameworkRepository->findOneBy(['name' => 'NIS2-UmsuCG']);
        if ($framework === null) {
            $io->error('Framework NIS2-UmsuCG not in DB.');
            return Command::FAILURE;
        }
        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0; $updated = 0;
        foreach (self::PARAGRAPHS as $reqId => $title) {
            $num = (int) ltrim($reqId, '§');
            $teil = match (true) {
                $num <= 3   => 'Teil 1 — Allgemeines',
                $num <= 8   => 'Teil 2 — BSI-Aufgaben',
                $num <= 35  => 'Teil 3 — Besonders wichtige + wichtige Einrichtungen',
                $num <= 43  => 'Teil 4 — Kritische Anlagen',
                $num <= 50  => 'Teil 5 — Sektorspezifika',
                $num <= 65  => 'Teil 6 — Aufsicht',
                $num <= 67  => 'Teil 7 — Bussgeld',
                default     => 'Teil 8 — Schlussvorschriften',
            };
            $req = $reqRepo->findOneBy(['framework' => $framework, 'requirementId' => $reqId]);
            if ($req === null) {
                $req = new ComplianceRequirement();
                $req->setFramework($framework);
                $req->setRequirementId($reqId);
                $req->setRequirementType('core');
                $req->setPriority(in_array($num, [30, 31, 32, 34, 41, 42, 43], true) ? 'high' : 'medium');
                $created++;
            } else {
                $updated++;
            }
            $req->setTitle(mb_substr($title, 0, 250));
            $req->setDescription(sprintf('NIS2UmsuCG / %s — %s. Quelle: BGBl. 2025 I Nr. 301 (NIS-2-Umsetzungs- und Cybersicherheitsstaerkungsgesetz) + BSI-Mindestanforderungen-Konsultation.', $reqId, $title));
            $req->setCategory($teil);
            $this->em->persist($req);
        }
        $this->em->flush();
        $io->success(sprintf('NIS2UmsuCG: %d created, %d updated. Total: %d.', $created, $updated, count(self::PARAGRAPHS)));
        return Command::SUCCESS;
    }
}
