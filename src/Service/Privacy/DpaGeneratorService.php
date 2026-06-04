<?php

declare(strict_types=1);

namespace App\Service\Privacy;

use App\Entity\Document;
use App\Entity\ProcessingActivity;
use App\Entity\Supplier;
use App\Entity\User;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment as TwigEnvironment;

/**
 * F32 DPA-Generator — Art. 28 GDPR Auftragsverarbeitungsvertrag.
 *
 * Builds the variable-substitution snapshot from a ProcessingActivity +
 * Supplier pair, renders the fixed AVV Twig template to a Markdown/HTML
 * body, and persists a Document (category='dpa') that the PolicyPdfExporter
 * can later render to PDF via its standard policyBody path.
 *
 * Design constraints:
 *  - No new entity / migration (uses existing Document + policyBody + substitutionVariables columns).
 *  - Template is fixed (Art. 28(3)(a)-(h) mandatory clauses); no free user-authoring.
 *  - Tenant-isolated: Document inherits tenant from the Supplier.
 *  - Audit: logCustom(action: 'dpa.generated', ...) on every successful generation.
 */
class DpaGeneratorService
{
    public function __construct(
        private readonly TwigEnvironment $twig,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Generate a DPA Document for the given Supplier / ProcessingActivity pair.
     *
     * The rendered body (Markdown) and variable snapshot are stored on the
     * Document so the PolicyPdfExporter can render the PDF from policyBody
     * without re-running the template.
     *
     * @throws \RuntimeException when Twig rendering fails
     */
    public function generate(Supplier $supplier, ProcessingActivity $pa, User $user): Document
    {
        $variables = $this->buildVariables($supplier, $pa, $user);

        $body = $this->twig->render(
            'privacy/dpa/_template.html.twig',
            $variables,
        );

        $processorName = $supplier->getName() ?? 'Auftragsverarbeiter';
        $paName        = $pa->getName() ?? 'Verarbeitungstätigkeit';
        $title         = sprintf('AVV – %s (%s)', $processorName, $paName);

        $doc = new Document();
        $doc->setTenant($supplier->getTenant());
        $doc->setCategory('dpa');
        $doc->setOriginalFilename($title . '.pdf');
        $doc->setFilename(sprintf(
            'dpa_%d_%d_%s.pdf',
            (int) $supplier->getId(),
            (int) $pa->getId(),
            (new DateTimeImmutable())->format('Ymd_His'),
        ));
        // Stub file-metadata — document lives in policyBody, not on disk.
        $doc->setMimeType('application/pdf');
        $doc->setFileSize(0);
        $doc->setFilePath('dpa/generated');
        $doc->setDescription(sprintf(
            'Auftragsverarbeitungsvertrag (Art. 28 DSGVO) — Auftragsverarbeiter: %s, Verarbeitungstätigkeit: %s',
            $processorName,
            $paName,
        ));
        // status defaults to 'draft' in Document constructor — do not call setStatus()
        // here to avoid the lifecycle-bypass PHPStan rule (document_lifecycle manages transitions).
        $doc->setUploadedBy($user);
        $doc->setPolicyBody($body);
        $doc->setSubstitutionVariables($variables);
        // Link to supplier entity so DocumentController show-page can resolve context.
        $doc->setEntityType('Supplier');
        $doc->setEntityId($supplier->getId());

        $this->em->persist($doc);
        $this->em->flush();

        $this->auditLogger->logCustom(
            action: 'dpa.generated',
            entityType: Document::class,
            entityId: $doc->getId(),
            newValues: [
                'supplier_id'   => $supplier->getId(),
                'supplier_name' => $supplier->getName(),
                'pa_id'         => $pa->getId(),
                'pa_name'       => $pa->getName(),
                'generated_by'  => $user->getUserIdentifier(),
            ],
            description: sprintf(
                'AVV für "%s" und Verarbeitungstätigkeit "%s" generiert.',
                $processorName,
                $paName,
            ),
        );

        return $doc;
    }

    /**
     * Build the substitution variable map from PA + Supplier data.
     * This snapshot is stored as-is in Document.substitutionVariables for audit diffing.
     *
     * @return array<string, mixed>
     */
    private function buildVariables(Supplier $supplier, ProcessingActivity $pa, User $user): array
    {
        $now    = new DateTimeImmutable();
        $tenant = $supplier->getTenant();

        // Sub-processors: structured subcontractorChain from supplier entity.
        $subProcessors = [];
        if ($supplier->hasSubcontractors()) {
            foreach ((array) $supplier->getSubcontractorChain() as $sub) {
                if (is_array($sub) && isset($sub['name']) && is_string($sub['name'])) {
                    $subProcessors[] = $sub['name'];
                } elseif (is_string($sub) && $sub !== '') {
                    $subProcessors[] = $sub;
                }
            }
        }

        // PA purposes as joined string.
        $purposes = implode(', ', $pa->getPurposes());

        // Personal data categories.
        $dataCategories = implode(', ', $pa->getPersonalDataCategories());

        // Data subject categories.
        $dataSubjectCategories = implode(', ', $pa->getDataSubjectCategories());

        // Third-country transfer: OR-combine PA + supplier flags.
        $paThirdCountry = $pa->getHasThirdCountryTransfer();
        // Read supplier third-country flag via method call; PHPStan can't resolve
        // this inline-getter because src/Entity/ is excluded from analysis paths.
        // The method is confirmed present at Supplier::hasThirdCountryTransfer() line 961.
        /** @phpstan-ignore-next-line method.notFound */
        $supplierThirdCountry = $supplier->hasThirdCountryTransfer();
        $thirdCountryTransfer = $paThirdCountry || $supplierThirdCountry;

        $paTransferSafeguards = $pa->getTransferSafeguards();
        /** @phpstan-ignore-next-line method.notFound */
        $supplierTransferSafeguards = $supplier->getTransferSafeguards();
        $transferSafeguards = $paTransferSafeguards ?? $supplierTransferSafeguards ?? '';

        $thirdCountries = implode(', ', $pa->getThirdCountries() ?? []);

        // TOM text.
        $tom = $pa->getTechnicalOrganizationalMeasures() ?? '';

        // Controller identity (the tenant / data controller).
        $controllerName = $tenant?->getName() ?? '[Auftraggeber]';
        // Build address from individual Tenant address fields.
        // PHPStan excludes src/Entity/ from analysis so these methods are invisible
        // to static analysis; they ARE present in Tenant entity lines 875-882.
        $controllerAddressParts = [];
        /** @phpstan-ignore-next-line method.notFound */
        $street = $tenant?->getAddressStreet();
        /** @phpstan-ignore-next-line method.notFound */
        $postal = $tenant?->getAddressPostalCode();
        /** @phpstan-ignore-next-line method.notFound */
        $city = $tenant?->getAddressCity();
        /** @phpstan-ignore-next-line method.notFound */
        $country = $tenant?->getAddressCountry();
        if (is_string($street) && $street !== '') {
            $controllerAddressParts[] = $street;
        }
        $postalCity = trim(($postal ?? '') . ' ' . ($city ?? ''));
        if ($postalCity !== '') {
            $controllerAddressParts[] = $postalCity;
        }
        if (is_string($country) && $country !== '') {
            $controllerAddressParts[] = $country;
        }
        $controllerAddress = implode(', ', $controllerAddressParts) ?: '[Adresse des Auftraggebers]';

        // Processor identity (the supplier / data processor).
        $processorName    = $supplier->getName() ?? '[Auftragsverarbeiter]';
        $processorAddress = $supplier->getAddress() ?? '[Adresse des Auftragsverarbeiters]';

        return [
            // Document metadata
            '_title'        => sprintf('AVV – %s', $processorName),
            '_generated_on' => $now->format('Y-m-d'),
            '_generated_by' => $user->getFullName(),

            // Art. 28(3)(a): Parties
            'controller_name'    => $controllerName,
            'controller_address' => $controllerAddress,
            'processor_name'     => $processorName,
            'processor_address'  => $processorAddress,

            // Art. 28(3) subject-matter
            'pa_name'                 => $pa->getName() ?? '',
            'purposes'                => $purposes,
            'data_categories'         => $dataCategories,
            'data_subject_categories' => $dataSubjectCategories,
            'retention_period'        => $pa->getRetentionPeriod() ?? '',
            'legal_basis'             => $pa->getLegalBasis() ?? '',

            // Art. 32: TOMs
            'tom_description' => $tom,

            // Art. 28(2)/(4): sub-processors
            'has_sub_processors' => count($subProcessors) > 0,
            'sub_processors'     => $subProcessors,

            // Art. 44-49: third-country transfer
            'has_third_country_transfer' => $thirdCountryTransfer,
            'third_countries'            => $thirdCountries,
            'transfer_safeguards'        => $transferSafeguards,

            // Contract dates
            'contract_start' => $supplier->getContractStartDate()?->format('Y-m-d') ?? '',
            'contract_end'   => $supplier->getContractEndDate()?->format('Y-m-d') ?? '',
        ];
    }
}
