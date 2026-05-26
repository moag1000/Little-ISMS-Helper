<?php

declare(strict_types=1);

namespace App\Service\Authority;

use App\Entity\Asset;
use App\Entity\AssetDependency;
use App\Entity\DoraDataFlow;
use App\Entity\Tenant;
use App\Repository\AssetDependencyRepository;
use App\Repository\AssetRepository;
use App\Repository\DoraDataFlowRepository;
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
        private readonly ?AssetDependencyRepository $assetDependencyRepository = null,
        private readonly ?DoraDataFlowRepository $dataFlowRepository = null,
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
        // RT_03 (data-flow) sub-table: tenant-scoped flows grouped by
        // supplier id for per-provider nesting below. NULL repo (legacy
        // call-sites that have not been re-autowired yet) degrades to an
        // empty map, preserving prior behaviour.
        $flowsBySupplierId = $this->groupFlowsBySupplierId(
            $this->dataFlowRepository?->findByTenant($tenant) ?? []
        );

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

        // ─── Element 3: Unit (monetary — tenant reporting currency) ───────────
        // Sprint-9 Bucket-6b: wire Tenant.reportingCurrency (defaults to EUR
        // when unset). The xbrli:unit id MUST equal the unitRef on monetary
        // facts (B_01.01.0040 below).
        $currency = strtoupper((string) ($tenant->getReportingCurrency() ?? 'EUR'));
        // Defensive: only accept ISO-4217-shaped codes; fall back to EUR
        // otherwise so the XBRL stays valid.
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'EUR';
        }
        $unit = $dom->createElementNS(self::NS_XBRLI, 'xbrli:unit');
        $unit->setAttribute('id', $currency);
        $measure = $dom->createElementNS(self::NS_XBRLI, 'xbrli:measure', 'iso4217:' . $currency);
        $unit->appendChild($measure);
        $root->appendChild($unit);

        // ─── Element 4: Reporting Entity identifier (B_01.01.0010) ────────────
        $el = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_01.01.0010');
        $el->setAttribute('contextRef', 'ctx_entity');
        $el->textContent = $tenant->getLegalName() ?? $tenant->getName() ?? 'Unknown Entity';
        $root->appendChild($el);
        $root->appendChild($dom->createComment(' B_01.01.0010: Reporting entity legal name — ESA RoI Art. 28(3)(a) '));

        // ─── Element 5: Reporting entity LEI (B_01.01.0020) ───────────────────
        // Sprint-9 Bucket-6b: wired to Tenant.leiCode (ISO 17442 — 20 char).
        // When the operator has not yet entered a LEI the element carries the
        // ESA-defined sentinel "N/A" so the document still validates.
        $el = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_01.01.0020');
        $el->setAttribute('contextRef', 'ctx_entity');
        $el->textContent = $tenant->getLeiCode() ?? 'N/A';
        $root->appendChild($el);
        $root->appendChild($dom->createComment(' B_01.01.0020: Reporting entity LEI — ISO 17442 / GLEIF — sourced from Tenant.leiCode '));

        // ─── Element 6: Report reference date (B_01.01.0030) ──────────────────
        $el = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_01.01.0030');
        $el->setAttribute('contextRef', 'ctx_period');
        $el->textContent = $reportingDate->format('Y-12-31'); // Year-end per ESA spec
        $root->appendChild($el);
        $root->appendChild($dom->createComment(' B_01.01.0030: Report reference date — end of reporting year '));

        // ─── Element 7: Currency (B_01.01.0040) ───────────────────────────────
        // Sprint-9 Bucket-6b: wired to Tenant.reportingCurrency (ISO 4217).
        // The `$currency` variable was already validated and uppercase-folded
        // when the xbrli:unit was emitted above.
        $el = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_01.01.0040');
        $el->setAttribute('contextRef', 'ctx_period');
        $el->setAttribute('unitRef', $currency);
        $el->textContent = $currency;
        $root->appendChild($el);
        $root->appendChild($dom->createComment(' B_01.01.0040: Reporting currency — ISO 4217 — sourced from Tenant.reportingCurrency '));

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

            // B_02.02.0020: Provider LEI (ISO 17442) — wired to Supplier.leiCode
            // (Sprint-9 Bucket-6b). Sentinel "N/A" when the operator has not
            // yet captured the GLEIF LEI for this provider.
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

            // ─── B_02.02.0060–0130 (Sprint-9 Bucket-6c) ───────────────────────
            // Provider-Details: contract dates, substitutability, exit strategy,
            // processing locations, certifications, audit rights, jurisdiction.
            //
            // Beyond 0130 the ESA taxonomy nests into RT_03/RT_04 sub-tables
            // (data-flow, function-mapping, subcontractor-chain). RT_03
            // (data-flow) is wired below via {@see App\Entity\DoraDataFlow}.
            // RT_04 (subcontractor-chain) + function-mapping remain deferred —
            // they need richer sub-entities (subcontractor join-table is in
            // place but function-mapping isn't), and we'd rather defer
            // cleanly than emit stub rows that get rejected by Arelle.

            // B_02.02.0060: Contract start date
            $contractStartEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_02.02.0060');
            $contractStartEl->setAttribute('contextRef', 'ctx_period');
            $contractStartEl->textContent = $supplier->getContractStartDate()?->format('Y-m-d') ?? '';
            $providerEl->appendChild($contractStartEl);

            // B_02.02.0070: Contract end date
            $contractEndEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_02.02.0070');
            $contractEndEl->setAttribute('contextRef', 'ctx_period');
            $contractEndEl->textContent = $supplier->getContractEndDate()?->format('Y-m-d') ?? '';
            $providerEl->appendChild($contractEndEl);

            // B_02.02.0080: Substitutability — easy|medium|hard (DORA Art. 30(2)(g))
            $substEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_02.02.0080');
            $substEl->setAttribute('contextRef', 'ctx_period');
            $substEl->textContent = $supplier->getSubstitutability() ?? 'medium';
            $providerEl->appendChild($substEl);

            // B_02.02.0090: Exit strategy in place — boolean. DORA Art. 28(8).
            $exitEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_02.02.0090');
            $exitEl->setAttribute('contextRef', 'ctx_period');
            $exitEl->textContent = $supplier->hasExitStrategy() ? 'true' : 'false';
            $providerEl->appendChild($exitEl);

            // B_02.02.0100: Data location — EEA / non-EEA. Derived from
            // countryOfHeadOffice (DE, FR, NL, ... = EEA; US, IN, ... = non-EEA).
            $dataLocEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_02.02.0100');
            $dataLocEl->setAttribute('contextRef', 'ctx_period');
            $dataLocEl->textContent = $this->isEeaCountryCode($supplier->getCountryOfHeadOffice()) ? 'EEA' : 'non_EEA';
            $providerEl->appendChild($dataLocEl);

            // B_02.02.0110: Data processing locations (JSON list of ISO-3166
            // alpha-2 country codes) — emitted as comma-joined inline element.
            // ESA-taxonomy nested-element form is deferred; flat list satisfies
            // the cardinality contract until the sub-element rows are spec'd.
            $procLocEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_02.02.0110');
            $procLocEl->setAttribute('contextRef', 'ctx_period');
            $locations = $supplier->getProcessingLocations() ?? [];
            $procLocEl->textContent = implode(',', array_map('strval', $locations));
            $providerEl->appendChild($procLocEl);

            // B_02.02.0120: Certifications — ISO 27001 + ISO 22301 flags plus
            // free-text. ESA expects an enum or list; we emit a "+"-joined
            // shorthand so the field is non-empty when at least one cert
            // exists.
            $certs = [];
            if ($supplier->isHasISO27001()) {
                $certs[] = 'ISO27001';
            }
            if ($supplier->isHasISO22301()) {
                $certs[] = 'ISO22301';
            }
            $freeCerts = trim((string) $supplier->getCertifications());
            if ($freeCerts !== '') {
                $certs[] = $freeCerts;
            }
            $certEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_02.02.0120');
            $certEl->setAttribute('contextRef', 'ctx_period');
            $certEl->textContent = implode('+', $certs);
            $providerEl->appendChild($certEl);

            // B_02.02.0130: Audit rights clause — boolean. Derived from
            // securityRequirements free-text presence; this is a stop-gap
            // until a dedicated `hasAuditRightsClause` flag lands.
            $auditEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_02.02.0130');
            $auditEl->setAttribute('contextRef', 'ctx_period');
            $auditEl->textContent = trim((string) $supplier->getSecurityRequirements()) !== '' ? 'true' : 'false';
            $providerEl->appendChild($auditEl);

            // ─── RT_03 sub-table — data-flow rows per provider ────────────
            // ESA Joint ITS on RoI Art. 28 / RT_03: each <roi:RT_03_data_flow>
            // entry describes a single data-flow between the financial
            // entity and the ICT third-party (this provider). Wired to
            // {@see App\Entity\DoraDataFlow}.
            $supplierId = $supplier->getId();
            $flowsForSupplier = $supplierId !== null
                ? ($flowsBySupplierId[$supplierId] ?? [])
                : [];

            foreach ($flowsForSupplier as $k => $flow) {
                $providerEl->appendChild($this->buildDataFlowElement($dom, $flow, $i + 1, $k + 1));
            }

            // RT_04 (subcontractor-chain) still deferred — kept as audit-gap
            // marker. RT_03 has moved from this comment to the loop above.
            $providerEl->appendChild($dom->createComment(
                ' TODO: B_02.02.0140–0999 + RT_04 subcontractor-chain — pending dedicated sub-entities '
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

        // ─── B_03.02 — ICT asset detail table (Sprint-9 Bucket-6c) ───────────
        // One <roi:B_03.02_asset> wrapper per DORA-relevant Asset, carrying
        // the mandatory ESA per-asset fields: identifier, name, type,
        // classification, CIA scoring, owner. RT_05 sub-table
        // (asset-dependency-graph) is emitted below; RT_06 (decommission-plan)
        // remains deferred.
        foreach ($assets as $j => $asset) {
            $assetEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.02_asset');
            $assetEl->setAttribute('id', sprintf('asset_%d', $j + 1));

            // B_03.02.0010: Asset identifier (internal numeric id).
            $idEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.02.0010');
            $idEl->setAttribute('contextRef', 'ctx_period');
            $idEl->textContent = (string) ($asset->getId() ?? 0);
            $assetEl->appendChild($idEl);

            // B_03.02.0020: Asset name.
            $assetNameEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.02.0020');
            $assetNameEl->setAttribute('contextRef', 'ctx_period');
            $assetNameEl->textContent = (string) ($asset->getName() ?? 'Unknown Asset');
            $assetEl->appendChild($assetNameEl);

            // B_03.02.0030: Asset type — internal taxonomy code (server,
            // application, database, ...). ESA mapping deferred; field carries
            // raw value for traceability.
            $assetTypeEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.02.0030');
            $assetTypeEl->setAttribute('contextRef', 'ctx_period');
            $assetTypeEl->textContent = (string) ($asset->getAssetType() ?? '');
            $assetEl->appendChild($assetTypeEl);

            // B_03.02.0040: Data classification — public|internal|confidential|restricted.
            $dataClassEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.02.0040');
            $dataClassEl->setAttribute('contextRef', 'ctx_period');
            $dataClassEl->textContent = (string) ($asset->getDataClassification() ?? 'internal');
            $assetEl->appendChild($dataClassEl);

            // B_03.02.0050: Confidentiality value (1-5).
            $confEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.02.0050');
            $confEl->setAttribute('contextRef', 'ctx_period');
            $confEl->textContent = (string) ($asset->getConfidentialityValue() ?? 0);
            $assetEl->appendChild($confEl);

            // B_03.02.0060: Integrity value (1-5).
            $intEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.02.0060');
            $intEl->setAttribute('contextRef', 'ctx_period');
            $intEl->textContent = (string) ($asset->getIntegrityValue() ?? 0);
            $assetEl->appendChild($intEl);

            // B_03.02.0070: Availability value (1-5).
            $availEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.02.0070');
            $availEl->setAttribute('contextRef', 'ctx_period');
            $availEl->textContent = (string) ($asset->getAvailabilityValue() ?? 0);
            $assetEl->appendChild($availEl);

            // B_03.02.0080: Asset owner — free text owner-name (legacy field).
            $ownerEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.02.0080');
            $ownerEl->setAttribute('contextRef', 'ctx_period');
            $ownerEl->textContent = (string) ($asset->getOwner() ?? '');
            $assetEl->appendChild($ownerEl);

            // B_03.02.0090: Asset location — physical-location relation falls
            // back to the legacy free-text location string.
            $locEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.02.0090');
            $locEl->setAttribute('contextRef', 'ctx_period');
            $locEl->textContent = (string) ($asset->getLocation() ?? '');
            $assetEl->appendChild($locEl);

            // B_03.02.0100: Lifecycle status (active|in_use|retired|...).
            $statusEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.02.0100');
            $statusEl->setAttribute('contextRef', 'ctx_period');
            $statusEl->textContent = (string) ($asset->getStatus() ?? 'active');
            $assetEl->appendChild($statusEl);

            $root->appendChild($assetEl);
        }

        // ─── B_03.03 — RT_05 asset-dependency-graph (Bucket-6 close) ─────────
        // DORA Art. 28(3)(c) requires a register of dependencies between
        // ICT assets — which support which business processes, which back
        // up which, etc. We walk the Asset.dependsOn adjacency list (BSI
        // 3.6 Schutzbedarfsvererbung graph) and emit one
        // <roi:B_03.03_dependency> wrapper per edge.
        //
        // Per-edge enrichment (dependencyType, criticalityImpact, notes)
        // is sourced from the AssetDependency join entity when present;
        // edges without enriched-metadata fall back to safe defaults
        // (requires / cascade) so an operator who only declared the raw
        // adjacency list still gets a valid export.
        //
        // RT_06 (decommission-plan) remains deferred — needs dedicated
        // decommission-plan entity not yet in the model.
        $this->emitAssetDependencyGraph($dom, $root, $assets, $tenant);

        $root->appendChild($dom->createComment(
            ' TODO: RT_06 decommission-plan — pending dedicated decommission-plan entity '
        ));

        $xml = $dom->saveXML();

        if ($xml === false) {
            throw new \App\Exception\Io\IoException('Failed to generate XBRL XML — DOMDocument::saveXML() returned false.');
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
     * Emits the RT_05 asset-dependency-graph (DORA Art. 28(3)(c)).
     *
     * Walks the Asset.dependsOn adjacency list and emits one
     * <roi:B_03.03_dependency> wrapper per edge. The set of source-assets
     * is restricted to the DORA-relevant assets that already appear in the
     * B_03.02 table (otherwise the export would leak non-RoI assets into
     * the dependency graph). Target-side assets are emitted regardless of
     * their DORA-relevance flag so that upstream non-DORA-tagged ICT
     * services (e.g. internal datacentre power) still appear as drivers
     * of DORA-relevant asset criticality.
     *
     * Per-edge enrichment (dependencyType, criticalityImpact, notes) is
     * sourced from the AssetDependency join entity when available; edges
     * without enriched-metadata fall back to safe defaults (requires /
     * cascade) so an operator who only declared the raw adjacency list
     * still gets a valid export.
     *
     * @param Asset[] $assets DORA-relevant assets already in scope for B_03.02
     */
    private function emitAssetDependencyGraph(
        DOMDocument $dom,
        DOMElement $root,
        array $assets,
        Tenant $tenant,
    ): void {
        // Index enriched edges by "{sourceId}:{targetId}" for O(1) lookup.
        /** @var array<string, AssetDependency> $enriched */
        $enriched = $this->assetDependencyRepository?->findByTenantKeyed($tenant) ?? [];

        // Collect edges from the legacy ManyToMany so an operator who only
        // entered the basic adjacency list still gets emitted. Real-world
        // assets always have a non-null id at this point (they came back
        // from findByTenantAndDoraRelevant). Unit tests sometimes pass
        // in-memory entities with null ids — the per-edge XML still
        // renders (id falls back to "0") which keeps the cardinality
        // assertion honest.
        $edges = [];
        foreach ($assets as $source) {
            foreach ($source->getDependsOn() as $target) {
                $edges[] = ['source' => $source, 'target' => $target];
            }
        }

        // Wrapper + count element so the regulator can audit the cardinality
        // at a glance without iterating the per-edge rows.
        $countEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.03.0010');
        $countEl->setAttribute('contextRef', 'ctx_period');
        $countEl->textContent = (string) count($edges);
        $root->appendChild($countEl);
        $root->appendChild($dom->createComment(
            ' B_03.03.0010: Total ICT asset-dependency edges (RT_05) — DORA Art. 28(3)(c) '
        ));

        foreach ($edges as $i => $edge) {
            /** @var Asset $source */
            $source = $edge['source'];
            /** @var Asset $target */
            $target = $edge['target'];

            $key = $source->getId() . ':' . $target->getId();
            $meta = $enriched[$key] ?? null;
            $type = $meta?->getDependencyType()->value ?? 'requires';
            $impact = $meta?->getCriticalityImpact()->value ?? 'cascade';
            $notes = $meta?->getNotes() ?? '';

            $edgeEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.03_dependency');
            $edgeEl->setAttribute('id', sprintf('dependency_%d', $i + 1));

            // B_03.03.0020: Source asset identifier (downstream — the asset
            // whose criticality is potentially elevated by the relation).
            $srcEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.03.0020');
            $srcEl->setAttribute('contextRef', 'ctx_period');
            $srcEl->textContent = (string) ($source->getId() ?? 0);
            $edgeEl->appendChild($srcEl);

            // B_03.03.0030: Target asset identifier (upstream — the asset
            // that the source depends on).
            $tgtEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.03.0030');
            $tgtEl->setAttribute('contextRef', 'ctx_period');
            $tgtEl->textContent = (string) ($target->getId() ?? 0);
            $edgeEl->appendChild($tgtEl);

            // B_03.03.0040: Dependency type — requires | backs_up |
            // shares_data | redundant_with.
            $typeEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.03.0040');
            $typeEl->setAttribute('contextRef', 'ctx_period');
            $typeEl->textContent = $type;
            $edgeEl->appendChild($typeEl);

            // B_03.03.0050: Criticality cascade behaviour — cascade |
            // isolated | partial.
            $impactEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.03.0050');
            $impactEl->setAttribute('contextRef', 'ctx_period');
            $impactEl->textContent = $impact;
            $edgeEl->appendChild($impactEl);

            // B_03.03.0060: Source asset name (denormalised for auditor
            // readability — saves an asset-id ↔ asset-name join).
            $srcNameEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.03.0060');
            $srcNameEl->setAttribute('contextRef', 'ctx_period');
            $srcNameEl->textContent = (string) ($source->getName() ?? '');
            $edgeEl->appendChild($srcNameEl);

            // B_03.03.0070: Target asset name (denormalised — see above).
            $tgtNameEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.03.0070');
            $tgtNameEl->setAttribute('contextRef', 'ctx_period');
            $tgtNameEl->textContent = (string) ($target->getName() ?? '');
            $edgeEl->appendChild($tgtNameEl);

            // B_03.03.0080: Optional free-text notes (e.g. "DB connection
            // via VPN"). Empty when no AssetDependency metadata exists.
            if ($notes !== '') {
                $notesEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:B_03.03.0080');
                $notesEl->setAttribute('contextRef', 'ctx_period');
                $notesEl->textContent = $notes;
                $edgeEl->appendChild($notesEl);
            }

            $root->appendChild($edgeEl);
        }
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

    /**
     * Groups DORA data-flows by their supplier id for O(1) per-provider
     * lookup in the main generate() loop. Flows with a null supplier are
     * silently skipped — the entity enforces NOT NULL at DB level, this
     * guard handles in-memory test stubs only.
     *
     * @param iterable<DoraDataFlow> $flows
     * @return array<int, list<DoraDataFlow>>
     */
    private function groupFlowsBySupplierId(iterable $flows): array
    {
        $out = [];
        foreach ($flows as $flow) {
            $sid = $flow->getSupplier()?->getId();
            if ($sid === null) {
                continue;
            }
            $out[$sid] ??= [];
            $out[$sid][] = $flow;
        }

        return $out;
    }

    /**
     * Builds one <roi:RT_03_data_flow> element for the ESA Joint ITS RT_03
     * sub-table. Field-level child elements follow the same B_xx naming
     * convention as the parent provider rows so Arelle taxonomy validation
     * stays consistent.
     *
     * Sub-elements emitted:
     *  - RT_03.0010 — direction (inbound/outbound/bidirectional)
     *  - RT_03.0020 — data categories (comma-joined)
     *  - RT_03.0030 — processing purpose (text, max 500)
     *  - RT_03.0040 — security measures (comma-joined)
     *  - RT_03.0050 — data volume (free text)
     *  - RT_03.0060 — cross-border indicator (true/false)
     *  - RT_03.0070 — receiving country (ISO 3166-1 alpha-2, only if set)
     */
    private function buildDataFlowElement(
        DOMDocument $dom,
        DoraDataFlow $flow,
        int $providerIndex,
        int $flowIndex,
    ): DOMElement {
        $flowEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:RT_03_data_flow');
        $flowEl->setAttribute('id', sprintf('provider_%d_flow_%d', $providerIndex, $flowIndex));

        // RT_03.0010 — direction
        $dirEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:RT_03.0010');
        $dirEl->setAttribute('contextRef', 'ctx_period');
        $dirEl->textContent = (string) ($flow->getDirection() ?? '');
        $flowEl->appendChild($dirEl);

        // RT_03.0020 — data categories (flat comma-joined string; ESA's
        // nested-element variant is deferred until the cardinality contract
        // is locked, mirrors how B_02.02.0110 handles processing-locations).
        $catEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:RT_03.0020');
        $catEl->setAttribute('contextRef', 'ctx_period');
        $catEl->textContent = implode(',', array_map('strval', $flow->getDataCategories()));
        $flowEl->appendChild($catEl);

        // RT_03.0030 — processing purpose
        $purposeEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:RT_03.0030');
        $purposeEl->setAttribute('contextRef', 'ctx_period');
        $purposeEl->textContent = (string) ($flow->getProcessingPurpose() ?? '');
        $flowEl->appendChild($purposeEl);

        // RT_03.0040 — security measures (comma-joined)
        $secEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:RT_03.0040');
        $secEl->setAttribute('contextRef', 'ctx_period');
        $secEl->textContent = implode(',', array_map('strval', $flow->getSecurityMeasures()));
        $flowEl->appendChild($secEl);

        // RT_03.0050 — data volume
        $volEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:RT_03.0050');
        $volEl->setAttribute('contextRef', 'ctx_period');
        $volEl->textContent = (string) ($flow->getDataVolume() ?? '');
        $flowEl->appendChild($volEl);

        // RT_03.0060 — cross-border boolean
        $cbEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:RT_03.0060');
        $cbEl->setAttribute('contextRef', 'ctx_period');
        $cbEl->textContent = $flow->isCrossBorder() ? 'true' : 'false';
        $flowEl->appendChild($cbEl);

        // RT_03.0070 — receiving country (only emit when cross-border &
        // country is set; otherwise the element is suppressed so Arelle does
        // not flag an empty ISO-3166 alpha-2 value).
        $recv = $flow->getReceivingCountry();
        if ($flow->isCrossBorder() && $recv !== null && $recv !== '') {
            $recvEl = $dom->createElementNS(self::NS_ESA_ROI, 'roi:RT_03.0070');
            $recvEl->setAttribute('contextRef', 'ctx_period');
            $recvEl->textContent = strtoupper($recv);
            $flowEl->appendChild($recvEl);
        }

        return $flowEl;
    }

    /**
     * Checks whether an ISO-3166 alpha-2 country code denotes an EEA member.
     * Used by B_02.02.0100 (data-location EEA / non-EEA gate).
     *
     * EEA = EU-27 + Iceland (IS) + Liechtenstein (LI) + Norway (NO).
     * Switzerland (CH) is intentionally NOT included — it is EFTA but not EEA.
     */
    private function isEeaCountryCode(?string $code): bool
    {
        if ($code === null || $code === '') {
            // Conservative default — treat unknown jurisdiction as non-EEA so
            // the regulator gets the stricter signal.
            return false;
        }
        $eea = [
            // EU-27
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
            // EEA non-EU
            'IS', 'LI', 'NO',
        ];

        return in_array(strtoupper($code), $eea, true);
    }
}
