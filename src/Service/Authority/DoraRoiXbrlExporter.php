<?php

declare(strict_types=1);

namespace App\Service\Authority;

use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\SupplierRepository;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;

/**
 * F30 — DORA Register of Information (RoI) XBRL Exporter.
 *
 * Generates an XBRL XML document conforming to the ESA Joint RoI taxonomy
 * for Article 28 DORA obligations (register of ICT third-party service providers).
 *
 * IMPORTANT: This implementation covers the top-10 mandatory data elements
 * from the ESA RoI taxonomy. The full ~200-element taxonomy is OUT OF SCOPE
 * for Sprint 8. Deferred elements are marked with TODO comments.
 *
 * References:
 *  - DORA Art. 28 — Register of Information on ICT Third-Party Providers
 *  - ESA Joint Guidelines JC 2023/86 — RoI reporting requirements
 *  - ESA Joint Implementing Technical Standard (ITS) on RoI — XBRL taxonomy
 *
 * Module gate: nis2_dora (enforced in controller, not here)
 */
final class DoraRoiXbrlExporter
{
    /** XBRL namespace for ESA RoI taxonomy (placeholder until ESA publishes final namespace URI) */
    private const string NS_XBRLI       = 'http://www.xbrl.org/2003/instance';
    private const string NS_XBRLDI      = 'http://xbrl.org/2006/xbrldi';
    private const string NS_LINK        = 'http://www.xbrl.org/2003/linkbase';
    private const string NS_XSI         = 'http://www.w3.org/2001/XMLSchema-instance';
    private const string NS_XSD         = 'http://www.w3.org/2001/XMLSchema';
    /** ESA RoI taxonomy namespace — update when ESA publishes official URI */
    private const string NS_ESA_ROI     = 'http://esa.europa.eu/xbrl/dora/roi/2024';
    private const string NS_ISO4217     = 'http://www.xbrl.org/2003/iso4217';

    public function __construct(
        private readonly SupplierRepository $supplierRepository,
        private readonly AssetRepository $assetRepository,
    ) {
    }

    /**
     * Generates XBRL XML for the given tenant's DORA Register of Information.
     *
     * The output is a well-formed UTF-8 XML string with:
     *  - xbrli:xbrl root element with all required namespace declarations
     *  - Context elements (period, entity)
     *  - Top-10 mandatory RoI data elements per ESA taxonomy
     *  - Per-supplier ICT provider entries
     *
     * @param Tenant $tenant The tenant for which to generate the RoI
     * @return string Well-formed UTF-8 XBRL XML
     */
    public function generate(Tenant $tenant): string
    {
        $reportingDate = new DateTimeImmutable();
        // DORA Phase 1: filter to isDoraRelevant=true only (Art. 28 RoI scope).
        // Operators flag entities explicitly; untagged entries are NOT exported.
        $suppliers = $this->supplierRepository->findByTenantAndDoraRelevant($tenant);
        $assets = $this->assetRepository->findByTenantAndDoraRelevant($tenant);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        // ─── Root element with namespace declarations ─────────────────────────
        $root = $dom->createElementNS(self::NS_XBRLI, 'xbrli:xbrl');
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:xbrli',
            self::NS_XBRLI
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:xbrldi',
            self::NS_XBRLDI
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:link',
            self::NS_LINK
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:xsi',
            self::NS_XSI
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:roi',
            self::NS_ESA_ROI
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:iso4217',
            self::NS_ISO4217
        );
        $dom->appendChild($root);

        // ─── Comment: generation metadata ─────────────────────────────────────
        $root->appendChild($dom->createComment(
            sprintf(
                ' DORA Register of Information — ESA RoI XBRL Export | Generated: %s | Tenant: %s | Sprint 8 F30 ',
                $reportingDate->format('Y-m-d\TH:i:s\Z'),
                $tenant->getName() ?? 'unknown'
            )
        ));
        $root->appendChild($dom->createComment(
            ' NOTE: This export covers the top-10 mandatory ESA RoI elements. '
            . 'Full ~200-element taxonomy implementation is deferred — see TODO comments. '
        ));

        // ─── Element 1: Context — reporting period ─────────────────────────────
        $context = $this->buildReportingPeriodContext($dom, $reportingDate, $tenant);
        $root->appendChild($context);

        // ─── Element 2: Context — entity identifier ────────────────────────────
        $entityContext = $this->buildEntityContext($dom, $tenant);
        $root->appendChild($entityContext);

        // ─── Element 3: Unit (monetary — EUR) ─────────────────────────────────
        $unit = $dom->createElementNS(self::NS_XBRLI, 'xbrli:unit');
        $unit->setAttribute('id', 'EUR');
        $measure = $dom->createElementNS(self::NS_XBRLI, 'xbrli:measure', 'iso4217:EUR');
        $unit->appendChild($measure);
        $root->appendChild($unit);

        // ─── Element 4: Reporting Entity identifier (B_01.01.0010) ────────────
        $el = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_01.01.0010');
        $el->setAttribute('contextRef', 'ctx_entity');
        $el->textContent = $tenant->getLegalName() ?? $tenant->getName() ?? 'Unknown Entity';
        $root->appendChild($el);
        $root->appendChild($dom->createComment(' B_01.01.0010: Reporting entity legal name — ESA RoI Art. 28(3)(a) '));

        // ─── Element 5: Reporting entity LEI (B_01.01.0020) ───────────────────
        // TODO: ESA taxonomy element B_01.01.0020 — LEI of reporting entity
        // Wire Tenant.leiCode (or derive from Tenant.registrationNumber) when available
        $el = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_01.01.0020');
        $el->setAttribute('contextRef', 'ctx_entity');
        $el->textContent = 'N/A'; // TODO: Tenant::getLeiCode() — add field in Sprint 9
        $root->appendChild($el);
        $root->appendChild($dom->createComment(' B_01.01.0020: Reporting entity LEI — TODO: wire Tenant.leiCode (Sprint 9) '));

        // ─── Element 6: Report reference date (B_01.01.0030) ──────────────────
        $el = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_01.01.0030');
        $el->setAttribute('contextRef', 'ctx_period');
        $el->textContent = $reportingDate->format('Y-12-31'); // Year-end per ESA spec
        $root->appendChild($el);
        $root->appendChild($dom->createComment(' B_01.01.0030: Report reference date — end of reporting year '));

        // ─── Element 7: Currency (B_01.01.0040) ───────────────────────────────
        // TODO: ESA taxonomy element B_01.01.0040 — reporting currency
        $el = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_01.01.0040');
        $el->setAttribute('contextRef', 'ctx_period');
        $el->setAttribute('unitRef', 'EUR');
        $el->textContent = 'EUR';
        $root->appendChild($el);
        $root->appendChild($dom->createComment(' B_01.01.0040: Reporting currency — EUR default '));

        // ─── Element 8: Total count of ICT third-party providers (B_02.01.0010) ─
        $el = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_02.01.0010');
        $el->setAttribute('contextRef', 'ctx_period');
        $el->textContent = (string) count($suppliers);
        $root->appendChild($el);
        $root->appendChild($dom->createComment(' B_02.01.0010: Total number of ICT third-party providers in register '));

        // ─── Element 9: Per-provider entries (B_02.02 table) ─────────────────
        foreach ($suppliers as $i => $supplier) {
            $providerEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_02.02_provider');
            $providerEl->setAttribute('id', sprintf('provider_%d', $i + 1));

            // B_02.02.0010: Provider name
            $nameEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_02.02.0010');
            $nameEl->setAttribute('contextRef', 'ctx_period');
            $nameEl->textContent = $supplier->getName() ?? 'Unknown Provider';
            $providerEl->appendChild($nameEl);

            // B_02.02.0020: Provider LEI — TODO: wire Supplier.leiCode
            $leiEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_02.02.0020');
            $leiEl->setAttribute('contextRef', 'ctx_period');
            $leiEl->textContent = $supplier->getLeiCode() ?? 'N/A';
            $providerEl->appendChild($leiEl);

            // B_02.02.0030: Country of head office
            $countryEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_02.02.0030');
            $countryEl->setAttribute('contextRef', 'ctx_period');
            $countryEl->textContent = $supplier->getCountryOfHeadOffice() ?? 'DE';
            $providerEl->appendChild($countryEl);

            // B_02.02.0040: Criticality — map internal criticality to ESA classification
            $critEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_02.02.0040');
            $critEl->setAttribute('contextRef', 'ctx_period');
            $critEl->textContent = $this->mapCriticalityToEsa($supplier->getCriticality());
            $providerEl->appendChild($critEl);

            // B_02.02.0050: Service description
            $svcEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_02.02.0050');
            $svcEl->setAttribute('contextRef', 'ctx_period');
            $svcEl->textContent = $supplier->getServiceProvided() ?? $supplier->getDescription() ?? '';
            $providerEl->appendChild($svcEl);

            // TODO: ESA taxonomy elements B_02.02.0060–B_02.02.9999:
            //   - B_02.02.0060: Contract start date
            //   - B_02.02.0070: Contract end date
            //   - B_02.02.0080: Substitutability
            //   - B_02.02.0090: Exit strategy
            //   - B_02.02.0100: Data location (EU/non-EU)
            //   - B_02.02.0110: Data processing location
            //   - B_02.02.0120: Certification (ISO 27001, SOC2, etc.)
            //   - B_02.02.0130: Audit rights clause
            //   - ... (remaining ~180 elements — Sprint 9+)
            $providerEl->appendChild($dom->createComment(
                ' TODO: B_02.02.0060–B_02.02.9999 — deferred to Sprint 9 (full ESA taxonomy) '
            ));

            $root->appendChild($providerEl);
        }

        // ─── Element 10: ICT asset count (B_03.01.0010) ───────────────────────
        $el = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.01.0010');
        $el->setAttribute('contextRef', 'ctx_period');
        $el->textContent = (string) count($assets);
        $root->appendChild($el);
        $root->appendChild($dom->createComment(
            ' B_03.01.0010: Total ICT assets in inventory — sourced from Asset entity '
        ));

        // TODO: ESA taxonomy table B_03 — ICT asset details (Sprint 9+)
        $root->appendChild($dom->createComment(
            ' TODO: B_03.02 ICT asset detail table — Sprint 9 (full ESA taxonomy) '
        ));

        $xml = $dom->saveXML();

        if ($xml === false) {
            throw new \RuntimeException('Failed to generate XBRL XML — DOMDocument::saveXML() returned false.');
        }

        return $xml;
    }

    /**
     * Computes SHA-256 hash of the XBRL XML payload for audit-trail integrity.
     */
    public function computePayloadHash(string $xbrlXml): string
    {
        return hash('sha256', $xbrlXml);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function buildReportingPeriodContext(DOMDocument $dom, DateTimeImmutable $date, Tenant $tenant): DOMElement
    {
        $context = $dom->createElementNS(self::NS_XBRLI, 'xbrli:context');
        $context->setAttribute('id', 'ctx_period');

        $entity = $dom->createElementNS(self::NS_XBRLI, 'xbrli:entity');
        $identifier = $dom->createElementNS(self::NS_XBRLI, 'xbrli:identifier', (string) ($tenant->getId() ?? '0'));
        $identifier->setAttribute('scheme', 'http://esa.europa.eu/dora/entity');
        $entity->appendChild($identifier);
        $context->appendChild($entity);

        $period = $dom->createElementNS(self::NS_XBRLI, 'xbrli:period');
        $startDate = $dom->createElementNS(
            self::NS_XBRLI,
            'xbrli:startDate',
            $date->format('Y') . '-01-01'
        );
        $endDate = $dom->createElementNS(
            self::NS_XBRLI,
            'xbrli:endDate',
            $date->format('Y') . '-12-31'
        );
        $period->appendChild($startDate);
        $period->appendChild($endDate);
        $context->appendChild($period);

        return $context;
    }

    private function buildEntityContext(DOMDocument $dom, Tenant $tenant): DOMElement
    {
        $context = $dom->createElementNS(self::NS_XBRLI, 'xbrli:context');
        $context->setAttribute('id', 'ctx_entity');

        $entity = $dom->createElementNS(self::NS_XBRLI, 'xbrli:entity');
        $identifier = $dom->createElementNS(self::NS_XBRLI, 'xbrli:identifier', (string) ($tenant->getId() ?? '0'));
        $identifier->setAttribute('scheme', 'http://esa.europa.eu/dora/entity');
        $entity->appendChild($identifier);
        $context->appendChild($entity);

        $period = $dom->createElementNS(self::NS_XBRLI, 'xbrli:period');
        $instant = $dom->createElementNS(
            self::NS_XBRLI,
            'xbrli:instant',
            (new DateTimeImmutable())->format('Y-m-d')
        );
        $period->appendChild($instant);
        $context->appendChild($period);

        return $context;
    }

    /**
     * Maps internal criticality strings to ESA RoI classification values.
     *
     * ESA RoI taxonomy uses: critical | important | other
     */
    private function mapCriticalityToEsa(?string $criticality): string
    {
        return match (strtolower((string) $criticality)) {
            'critical', 'hoch', 'high' => 'critical',
            'medium', 'mittel', 'important' => 'important',
            default => 'other',
        };
    }
}
