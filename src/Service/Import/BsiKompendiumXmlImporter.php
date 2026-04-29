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
 * BSI IT-Grundschutz-Kompendium XML-Importer (DocBook-Format).
 *
 * Verarbeitet das offiziell vom BSI publizierte DocBook-5.0-XML
 * (`XML_Kompendium_<YEAR>.xml`) aus dem Kompendium-Download auf
 * bsi.bund.de/SharedDocs/Downloads/DE/BSI/Grundschutz.
 *
 * Struktur der BSI-XML:
 *
 *   <book xmlns="http://docbook.org/ns/docbook">
 *     <chapter><title>ISMS Sicherheitsmanagement</title>
 *       <section><title>ISMS.1 Sicherheitsmanagement</title>
 *         <section><title>ISMS.1.A1 Übernahme der Gesamtverantwortung … (B) [Institutionsleitung]</title>
 *           <para>Die Leitung MUSS …</para>
 *           <para>…</para>
 *         </section>
 *         …
 *       </section>
 *       …
 *     </chapter>
 *     …
 *   </book>
 *
 * Klassifikation wird aus dem Titel-Suffix gelesen:
 *   `(B)` → absicherungsStufe=basis, anforderungsTyp=muss
 *   `(S)` → absicherungsStufe=standard, anforderungsTyp=sollte
 *   `(H)` → absicherungsStufe=kern,     anforderungsTyp=kann (erhöhter Schutzbedarf)
 *   `ENTFALLEN` → wird übersprungen (deprecated)
 *
 * Der Titel wird um Klassifikation und Rollen-Zuordnungen `[…]`
 * bereinigt; der Beschreibungstext wird aus allen `<para>`-Elementen
 * der Anforderungs-Section zusammengesetzt.
 *
 * Idempotent: bestehende `requirementId` werden übersprungen,
 * nicht überschrieben — operator-gepflegte Anpassungen überleben
 * Re-Imports.
 */
final class BsiKompendiumXmlImporter
{
    private const SOURCE_FRAMEWORK_CODE = 'BSI_GRUNDSCHUTZ';
    private const DOCBOOK_NS = 'http://docbook.org/ns/docbook';

    private const LAYER_PREFIXES = ['ISMS', 'ORP', 'CON', 'OPS', 'DER', 'APP', 'SYS', 'IND', 'NET', 'INF'];

    /** @var array<string, array{stufe: string, typ: string, priority: string}> */
    private const CLASSIFICATION = [
        'B' => ['stufe' => 'basis',    'typ' => 'muss',   'priority' => 'critical'],
        'S' => ['stufe' => 'standard', 'typ' => 'sollte', 'priority' => 'high'],
        'H' => ['stufe' => 'kern',     'typ' => 'kann',   'priority' => 'high'],
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
     *     requirements_skipped_deprecated: int,
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
            'requirements_skipped_deprecated' => 0,
            'created' => 0,
            'skipped_existing' => 0,
            'errors' => [],
        ];

        $framework = $this->frameworkRepository->findOneBy(['code' => self::SOURCE_FRAMEWORK_CODE]);
        if (!$framework instanceof ComplianceFramework) {
            $result['errors'][] = [
                'context' => 'framework',
                'message' => sprintf('Framework %s nicht geladen.', self::SOURCE_FRAMEWORK_CODE),
            ];
            return $result;
        }

        $previousInternal = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $ok = $doc->loadXML($xmlContent, LIBXML_NONET | LIBXML_PARSEHUGE);
        if (!$ok) {
            foreach (libxml_get_errors() as $err) {
                $result['errors'][] = ['context' => 'xml', 'message' => trim($err->message)];
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previousInternal);
            return $result;
        }
        libxml_use_internal_errors($previousInternal);

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('db', self::DOCBOOK_NS);

        // Iterate every section whose title matches "<BausteinId>.A<N>" —
        // that's an Anforderung. Walk up to discover the containing
        // Baustein (parent section whose title starts with the same prefix
        // without the ".A<N>" suffix).
        $anforderungPattern = '/^((?:' . implode('|', self::LAYER_PREFIXES) . ')\.[A-Z0-9.]+)\.A(\d+)\s+(.+?)(?:\s*\(([BSH])\))?(?:\s*\[[^\]]*\])?\s*$/u';

        $titles = $xpath->query('//db:section/db:title');
        if ($titles === false) {
            $result['errors'][] = ['context' => 'xpath', 'message' => 'XPath über //section/title fehlgeschlagen.'];
            return $result;
        }

        $seenBausteine = [];

        foreach ($titles as $titleNode) {
            $rawTitle = $this->normaliseWhitespace($titleNode->textContent);
            if ($rawTitle === '') {
                continue;
            }

            if (!preg_match($anforderungPattern, $rawTitle, $match)) {
                continue;
            }
            $bausteinId = $match[1];
            $reqIndex = (int) $match[2];
            $titleBody = trim($match[3]);
            $classification = $match[4] ?? '';

            if (str_contains(strtoupper($titleBody), 'ENTFALLEN')) {
                $result['requirements_skipped_deprecated']++;
                continue;
            }

            if (!array_key_exists($classification, self::CLASSIFICATION)) {
                // Ohne Klassifikation — einige wenige Einträge sind so;
                // treffe die konservative Annahme „basis".
                $classification = 'B';
            }

            if (!isset($seenBausteine[$bausteinId])) {
                $seenBausteine[$bausteinId] = true;
                $result['bausteine_read']++;
            }

            $result['requirements_read']++;

            $requirementId = sprintf('%s.A%d', $bausteinId, $reqIndex);
            $existing = $this->requirementRepository->findOneBy([
                'framework' => $framework,
                'requirementId' => $requirementId,
            ]);
            if ($existing instanceof ComplianceRequirement) {
                $result['skipped_existing']++;
                continue;
            }

            $section = $titleNode->parentNode;
            $description = $section instanceof \DOMElement
                ? $this->collectParagraphs($section)
                : '';

            $bausteinTitle = $this->findBausteinTitle($xpath, $bausteinId);
            $category = $bausteinTitle !== ''
                ? sprintf('%s %s', $bausteinId, $bausteinTitle)
                : $bausteinId;

            $classInfo = self::CLASSIFICATION[$classification];
            $req = new ComplianceRequirement();
            $req->setFramework($framework)
                ->setRequirementId($requirementId)
                ->setTitle($titleBody)
                ->setDescription($description !== '' ? $description : $titleBody)
                ->setCategory($category)
                ->setPriority($classInfo['priority'])
                ->setAbsicherungsStufe($classInfo['stufe'])
                ->setAnforderungsTyp($classInfo['typ'])
                ->setDataSourceMapping([
                    'layer' => $this->layerOf($bausteinId),
                    'baustein' => $bausteinId,
                    'source' => 'BSI-Kompendium-DocBook-Import',
                    'classification' => $classification,
                ]);

            if ($persist) {
                $this->entityManager->persist($req);
            }
            $result['created']++;

            if ($persist && $result['created'] % 100 === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear(ComplianceRequirement::class);
                // Framework neu laden nach clear()
                $framework = $this->frameworkRepository->findOneBy(['code' => self::SOURCE_FRAMEWORK_CODE]);
                if (!$framework instanceof ComplianceFramework) {
                    $result['errors'][] = ['context' => 'flush-chunk', 'message' => 'Framework nach clear() verloren.'];
                    break;
                }
            }
        }

        if ($persist) {
            $framework->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();
        }

        return $result;
    }

    /**
     * Sammelt den Inhalt aller direkten `<para>`-Kinder einer Section
     * und fügt sie zu einem Beschreibungstext zusammen. Unterrichts-
     * Anweisungen/Umsetzungshinweise befinden sich in separaten
     * Sub-Sections und werden NICHT mitgenommen.
     */
    private function collectParagraphs(\DOMElement $section): string
    {
        $parts = [];
        foreach ($section->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if ($child->localName !== 'para') {
                continue;
            }
            $text = $this->normaliseWhitespace($child->textContent);
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        return implode("\n\n", $parts);
    }

    private function findBausteinTitle(\DOMXPath $xpath, string $bausteinId): string
    {
        $safeId = addslashes($bausteinId);
        // Suche das erste Section-Title, das genau "<bausteinId> <freitext>"
        // enthält (ohne weitere .A-Ebene).
        $nodes = $xpath->query(sprintf(
            "//db:section/db:title[starts-with(normalize-space(text()), '%s ')]",
            $safeId
        ));
        if ($nodes === false) {
            return '';
        }
        foreach ($nodes as $node) {
            $text = $this->normaliseWhitespace($node->textContent);
            if (strpos($text, $bausteinId . '.A') === 0) {
                // Das ist eine Anforderung, nicht der Baustein selbst.
                continue;
            }
            // Schneide Prefix + Leerzeichen ab
            return trim(substr($text, strlen($bausteinId) + 1));
        }
        return '';
    }

    private function layerOf(string $bausteinId): string
    {
        $pos = strpos($bausteinId, '.');
        if ($pos === false) {
            return $bausteinId;
        }
        return substr($bausteinId, 0, $pos);
    }

    private function normaliseWhitespace(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
