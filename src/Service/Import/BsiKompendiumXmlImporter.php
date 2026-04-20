<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * BSI IT-Grundschutz-Kompendium XML-Importer.
 *
 * Importiert BSI-Grundschutz-Anforderungen aus einem XML-Profil
 * im Verinice-kompatiblen Format. BSI publiziert die Kompendium-Daten
 * im Verinice-XML-Format als Teil des Grundschutz-Kompendium-Downloads.
 *
 * Unterstütztes XML-Schema:
 *
 *   <bsi-grundschutz edition="2023">
 *     <baustein id="SYS.1.2" title="Windows Server" layer="SYS">
 *       <anforderung id="SYS.1.2.A1" stufe="basis" typ="muss">
 *         <titel>Planung des Einsatzes</titel>
 *         <beschreibung>Bevor ein Windows Server eingesetzt wird…</beschreibung>
 *       </anforderung>
 *       <anforderung id="SYS.1.2.A8" stufe="standard" typ="sollte">
 *         <titel>Lokaler Schreibschutz</titel>
 *         <beschreibung>…</beschreibung>
 *       </anforderung>
 *     </baustein>
 *     …
 *   </bsi-grundschutz>
 *
 * Pro-Anforderungs-Attribute:
 *   id        (Pflicht)  — BSI-Anforderungs-ID, z. B. "SYS.1.2.A1"
 *   stufe     (optional) — "basis" | "standard" | "kern"
 *   typ       (optional) — "muss" | "sollte" | "kann"
 *
 * Kind-Elemente:
 *   <titel>         — Anforderungstitel (Kurzform)
 *   <beschreibung>  — Vollständiger Anforderungstext
 *
 * Der Importer ist **idempotent**: bestehende Anforderungen mit
 * gleichem `requirementId` werden übersprungen (nicht überschrieben).
 * Dadurch können Operator-gepflegte Anpassungen eine Re-Import-Runde
 * überleben.
 *
 * Gibt ein strukturiertes Ergebnis zurück, damit der aufrufende
 * Command einen Dry-Run-Modus ohne Flush-Gymnastik bauen kann.
 */
final class BsiKompendiumXmlImporter
{
    private const SOURCE_FRAMEWORK_CODE = 'BSI_GRUNDSCHUTZ';

    private const PRIORITY_BY_STUFE = [
        'basis' => 'critical',
        'standard' => 'high',
        'kern' => 'high',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
    ) {
    }

    /**
     * @return array{
     *     bausteine_read: int,
     *     requirements_read: int,
     *     created: int,
     *     skipped_existing: int,
     *     errors: list<array{context: string, message: string}>,
     * }
     */
    public function import(string $xmlContent, bool $persist = true): array
    {
        $result = [
            'bausteine_read' => 0,
            'requirements_read' => 0,
            'created' => 0,
            'skipped_existing' => 0,
            'errors' => [],
        ];

        $framework = $this->frameworkRepository->findOneBy(['code' => self::SOURCE_FRAMEWORK_CODE]);
        if (!$framework instanceof ComplianceFramework) {
            $result['errors'][] = [
                'context' => 'framework',
                'message' => sprintf('Framework %s nicht geladen. Zuerst app:load-bsi-grundschutz-requirements.', self::SOURCE_FRAMEWORK_CODE),
            ];
            return $result;
        }

        $previousInternal = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) {
            foreach (libxml_get_errors() as $err) {
                $result['errors'][] = ['context' => 'xml', 'message' => trim($err->message)];
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previousInternal);
            return $result;
        }
        libxml_use_internal_errors($previousInternal);

        $bausteine = $xml->baustein ?? [];
        foreach ($bausteine as $bausteinNode) {
            $result['bausteine_read']++;
            $bausteinTitle = (string) ($bausteinNode['title'] ?? $bausteinNode->titel ?? '');
            $bausteinId = (string) ($bausteinNode['id'] ?? '');

            if ($bausteinId === '') {
                $result['errors'][] = ['context' => 'baustein', 'message' => 'Baustein ohne id-Attribut übersprungen.'];
                continue;
            }

            $layer = $this->layerOf($bausteinId);

            foreach ($bausteinNode->anforderung ?? [] as $reqNode) {
                $result['requirements_read']++;
                $reqId = (string) ($reqNode['id'] ?? '');
                $title = trim((string) ($reqNode->titel ?? ''));
                $description = trim((string) ($reqNode->beschreibung ?? ''));

                if ($reqId === '' || $title === '' || $description === '') {
                    $result['errors'][] = [
                        'context' => $bausteinId,
                        'message' => sprintf(
                            'Anforderung ohne id/titel/beschreibung übersprungen (id="%s", titel=%d Z., beschreibung=%d Z.).',
                            $reqId,
                            strlen($title),
                            strlen($description)
                        ),
                    ];
                    continue;
                }

                $existing = $this->requirementRepository->findOneBy([
                    'complianceFramework' => $framework,
                    'requirementId' => $reqId,
                ]);
                if ($existing instanceof ComplianceRequirement) {
                    $result['skipped_existing']++;
                    continue;
                }

                $stufe = strtolower(trim((string) ($reqNode['stufe'] ?? 'basis')));
                if (!in_array($stufe, ['basis', 'standard', 'kern'], true)) {
                    $stufe = 'basis';
                }
                $typ = strtolower(trim((string) ($reqNode['typ'] ?? '')));
                if (!in_array($typ, ['muss', 'sollte', 'kann'], true)) {
                    $typ = match ($stufe) {
                        'standard' => 'sollte',
                        'kern' => 'kann',
                        default => 'muss',
                    };
                }

                $priority = self::PRIORITY_BY_STUFE[$stufe] ?? 'medium';

                $req = new ComplianceRequirement();
                $req->setFramework($framework)
                    ->setRequirementId($reqId)
                    ->setTitle($title)
                    ->setDescription($description)
                    ->setCategory(sprintf('%s %s', $bausteinId, $bausteinTitle !== '' ? $bausteinTitle : $layer))
                    ->setPriority($priority)
                    ->setAbsicherungsStufe($stufe)
                    ->setAnforderungsTyp($typ)
                    ->setDataSourceMapping([
                        'layer' => $layer,
                        'baustein' => $bausteinId,
                        'source' => 'BSI-Kompendium-XML-Import',
                    ]);

                if ($persist) {
                    $this->entityManager->persist($req);
                }
                $result['created']++;
            }
        }

        if ($persist) {
            $framework->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();
        }

        return $result;
    }

    private function layerOf(string $bausteinId): string
    {
        $pos = strpos($bausteinId, '.');
        if ($pos === false) {
            return $bausteinId;
        }
        return substr($bausteinId, 0, $pos);
    }
}
