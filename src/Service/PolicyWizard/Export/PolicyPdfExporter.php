<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Export;

use App\Entity\Document;
use App\Entity\TenantBranding;
use App\Repository\EntityTagRepository;
use App\Repository\TenantBrandingRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment as TwigEnvironment;

/**
 * Policy-Wizard W7-A — PDF export with TenantBranding letterhead.
 *
 * Renders a {@see Document} (cover page + body + footer) into a
 * single-PDF binary using {@see Dompdf} (already in `composer.json` —
 * no new dependency). Letterhead colors, logo path and footer prose
 * come from the per-tenant {@see TenantBranding} row that W1-A seeds;
 * tenants without a branding row fall back to neutral defaults.
 *
 * The body is treated as Markdown. Line-breaks become paragraphs;
 * leading `#` / `##` / `###` become `h1`/`h2`/`h3`; bold (`**…**`),
 * italic (`*…*`) and inline-code (`` `…` ``) are inlined. We do NOT
 * pull a full Markdown parser — the wizard only emits a tightly
 * controlled subset (see `templates/messages*.yaml::policy.*.body`).
 *
 * Spec: `docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md`
 * lines 295-302 (PDF export with tenant CI/letterhead + ZIP bulk
 * export for auditors).
 */
class PolicyPdfExporter
{
    public function __construct(
        private readonly TwigEnvironment $twig,
        private readonly ?TenantBrandingRepository $brandingRepository = null,
        private readonly ?EntityTagRepository $entityTagRepository = null,
    ) {
    }

    /**
     * Render one Document → PDF binary. When `$branding` is null we
     * lazy-look-up via the repository (when wired) using the document's
     * tenant.
     */
    public function exportDocument(Document $doc, ?TenantBranding $branding = null): string
    {
        $branding = $this->resolveBranding($doc, $branding);
        $variables = $doc->getSubstitutionVariables() ?? [];

        $bodyHtml = $this->twig->render(
            'policy_wizard/export/_pdf_document.html.twig',
            [
                'document'    => $doc,
                'tenant'      => $doc->getTenant(),
                'branding'    => $branding,
                'cover'       => $this->buildCoverContext($doc, $variables),
                'body_html'   => $this->renderBodyHtml($doc),
                'footer'      => $this->buildFooterContext($doc, $variables),
                'primary'     => $branding?->getPrimaryColor() ?? '#0d6efd',
                'secondary'   => $branding?->getSecondaryColor() ?? '#6c757d',
                'font_family' => $branding?->getFontFamily() ?? 'Inter',
                'logo_path'   => $branding?->getLogoPath(),
                'header_html' => $branding?->getHeaderHtml(),
                'footer_html' => $branding?->getFooterHtml(),
            ],
        );

        return $this->renderPdf($bodyHtml);
    }

    /**
     * Render N Documents in one call. Returns a `[doc_id => binary]`
     * map. Branding is resolved once per call so the repository is hit
     * at most a handful of times when `$branding` is null.
     *
     * @param list<Document> $documents
     * @return array<int, string>
     */
    public function exportBatch(array $documents, ?TenantBranding $branding = null): array
    {
        $out = [];
        $brandingByTenant = [];
        foreach ($documents as $doc) {
            if ($branding !== null) {
                $resolved = $branding;
            } else {
                $tenantId = $doc->getTenant()?->getId() ?? 0;
                if (!array_key_exists($tenantId, $brandingByTenant)) {
                    $brandingByTenant[$tenantId] = $this->resolveBranding($doc, null);
                }
                $resolved = $brandingByTenant[$tenantId];
            }
            $id = (int) ($doc->getId() ?? 0);
            $out[$id] = $this->exportDocument($doc, $resolved);
        }
        return $out;
    }

    private function resolveBranding(Document $doc, ?TenantBranding $branding): ?TenantBranding
    {
        if ($branding !== null) {
            return $branding;
        }
        if ($this->brandingRepository === null) {
            return null;
        }
        $tenant = $doc->getTenant();
        if ($tenant === null) {
            return null;
        }
        return $this->brandingRepository->findOneByTenant($tenant);
    }

    /**
     * @param array<string, mixed> $variables
     * @return array{title: string, version: int, valid_from: ?string, valid_until: ?string, approver_chain: list<string>, generated_on: string, topic: ?string, standard: ?string, dora_validity_date: ?string, climate_change_aware: bool}
     */
    private function buildCoverContext(Document $doc, array $variables): array
    {
        $template = $doc->getGeneratedFromTemplate();

        $title = $variables['_title'] ?? null;
        if (!is_string($title) || $title === '') {
            $title = $doc->getOriginalFilename() ?? 'Policy Document';
        }

        $version = is_int($variables['_template_version'] ?? null)
            ? (int) $variables['_template_version']
            : ($template?->getVersion() ?? 1);

        $approverChain = [];
        $rawChain = $variables['approval.chain'] ?? $variables['approval_chain'] ?? null;
        if (is_array($rawChain)) {
            foreach ($rawChain as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $approverChain[] = $entry;
                }
            }
        } elseif (is_string($rawChain) && $rawChain !== '') {
            $approverChain[] = $rawChain;
        }

        // Compliance-Manager Wish — surface DORA-validity + Climate-Change
        // marker on the PDF cover. Tag-set is resolved via EntityTagRepository
        // when the dependency is wired (legacy unit-tests construct the
        // exporter without it; in that case both flags fall back to null/false).
        $tagNames = $this->resolveActiveTagNames($doc);
        $doraValidity = Document::parseDoraValidityFromTags($tagNames);
        $climateAware = Document::isClimateChangeAwareFromTags($tagNames);

        return [
            'title'                => $title,
            'version'              => $version,
            'valid_from'           => $this->stringOrNull($variables['valid_from'] ?? null),
            'valid_until'          => $this->stringOrNull($variables['valid_until'] ?? null),
            'approver_chain'       => $approverChain,
            'generated_on'         => ($doc->getUploadedAt() ?? new \DateTimeImmutable())->format('Y-m-d'),
            'topic'                => $template?->getTopic(),
            'standard'             => $template?->getStandard(),
            'dora_validity_date'   => $doraValidity?->format('Y-m-d'),
            'climate_change_aware' => $climateAware,
        ];
    }

    /**
     * Resolve the active EntityTag names for a Document. Returns an
     * empty list when the repository is not wired (test seam) or the
     * Document has no id yet.
     *
     * @return list<string>
     */
    private function resolveActiveTagNames(Document $doc): array
    {
        if ($this->entityTagRepository === null) {
            return [];
        }
        $id = $doc->getId();
        if ($id === null) {
            return [];
        }
        $names = [];
        foreach ($this->entityTagRepository->findActiveFor(Document::class, $id) as $entityTag) {
            $tag = $entityTag->getTag();
            if ($tag !== null) {
                $name = $tag->getName();
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }
        return $names;
    }

    /**
     * @param array<string, mixed> $variables
     * @return array{batch_id: ?string, system_attribution: string, version: string, locally_edited: bool, locally_edited_by: ?string, locally_edited_at: ?string}
     */
    private function buildFooterContext(Document $doc, array $variables): array
    {
        $batchId = null;
        foreach (['audit_trail.batch_id', 'audit.batch_id', 'batch_id'] as $key) {
            if (isset($variables[$key]) && is_string($variables[$key]) && $variables[$key] !== '') {
                $batchId = $variables[$key];
                break;
            }
        }
        if ($batchId === null) {
            $hash = $doc->getSha256Hash();
            if (is_string($hash) && $hash !== '') {
                $batchId = substr($hash, 0, 12);
            }
        }

        $locallyEdited = $doc->hasPostGenerationEdits();
        $editor = $doc->getPolicyBodyEditedBy();
        $editedAt = $doc->getPolicyBodyEditedAt();

        return [
            'batch_id'           => $batchId,
            'system_attribution' => 'Generated by Little ISMS Helper',
            'version'            => $this->resolveAppVersion(),
            'locally_edited'     => $locallyEdited,
            'locally_edited_by'  => $editor?->getFullName(),
            'locally_edited_at'  => $editedAt?->format('Y-m-d'),
        ];
    }

    /**
     * Tiny safe-subset Markdown → HTML renderer. Handles headings,
     * paragraphs, bullet lists, blockquotes, bold/italic/inline-code.
     * Anything we don't recognise is HTML-escaped and emitted as a
     * paragraph.
     *
     * Body resolution chain (first non-empty wins):
     *   1. `Document.policyBody` — persisted rendered body (post-W7-X
     *      writes from {@see \App\Service\PolicyWizard\DocumentGenerator}
     *      OR a tenant's manual customisation).
     *   2. `substitutionVariables._body` — legacy snapshot path used
     *      by older runs that did not persist `policyBody`.
     *   3. `Document.description` — legacy uploaded-file path.
     *   4. Empty-body stub.
     */
    private function renderBodyHtml(Document $doc): string
    {
        return $this->renderBodyHtmlPublic($doc, true);
    }

    /**
     * Public surface so the document show-view (and other callers)
     * can render the same safe-subset Markdown → HTML preview that
     * the PDF exporter ships. When `$withFallback` is false and no
     * body is resolvable, returns `null` — callers can hide the
     * preview block instead of emitting a stub.
     */
    public function renderBodyHtmlPublic(Document $doc, bool $withFallback = true): ?string
    {
        $effective = $doc->getEffectivePolicyBody();
        if (is_string($effective) && $effective !== '') {
            return $this->markdownToHtml($effective);
        }

        $vars = $doc->getSubstitutionVariables() ?? [];
        if (isset($vars['_body']) && is_string($vars['_body']) && $vars['_body'] !== '') {
            return $this->markdownToHtml($vars['_body']);
        }

        $description = (string) ($doc->getDescription() ?? '');
        if ($description !== '') {
            return $this->markdownToHtml($description);
        }

        return $withFallback ? $this->markdownToHtml('_(empty body)_') : null;
    }

    private function markdownToHtml(string $markdown): string
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $html = '';
        $listOpen = false;
        $paragraph = [];

        $flushParagraph = function () use (&$paragraph, &$html): void {
            if ($paragraph === []) {
                return;
            }
            $text = implode(' ', $paragraph);
            $html .= '<p>' . $this->inlineMd($text) . "</p>\n";
            $paragraph = [];
        };

        $closeList = function () use (&$listOpen, &$html): void {
            if ($listOpen) {
                $html .= "</ul>\n";
                $listOpen = false;
            }
        };

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $flushParagraph();
                $closeList();
                continue;
            }
            if (str_starts_with($trim, '### ')) {
                $flushParagraph();
                $closeList();
                $html .= '<h3>' . $this->inlineMd(substr($trim, 4)) . "</h3>\n";
                continue;
            }
            if (str_starts_with($trim, '## ')) {
                $flushParagraph();
                $closeList();
                $html .= '<h2>' . $this->inlineMd(substr($trim, 3)) . "</h2>\n";
                continue;
            }
            if (str_starts_with($trim, '# ')) {
                $flushParagraph();
                $closeList();
                $html .= '<h1>' . $this->inlineMd(substr($trim, 2)) . "</h1>\n";
                continue;
            }
            if (str_starts_with($trim, '> ')) {
                $flushParagraph();
                $closeList();
                $html .= '<blockquote>' . $this->inlineMd(substr($trim, 2)) . "</blockquote>\n";
                continue;
            }
            if (str_starts_with($trim, '- ') || str_starts_with($trim, '* ')) {
                $flushParagraph();
                if (!$listOpen) {
                    $html .= "<ul>\n";
                    $listOpen = true;
                }
                $html .= '<li>' . $this->inlineMd(substr($trim, 2)) . "</li>\n";
                continue;
            }
            $paragraph[] = $trim;
        }
        $flushParagraph();
        $closeList();

        return $html;
    }

    private function inlineMd(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*(.+?)\*/u', '<em>$1</em>', $escaped) ?? $escaped;
        $escaped = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $escaped) ?? $escaped;
        return $escaped;
    }

    private function renderPdf(string $html): string
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultPaperSize', 'A4');
        $options->set('defaultFont', 'Helvetica');
        // Avoid touching the filesystem for fonts when the host has none.
        $options->set('isFontSubsettingEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $output = $dompdf->output();
        return $output ?? '';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return is_string($value) ? $value : null;
    }

    /**
     * Resolve the running application version. Falls back to "dev" when
     * the marker file is absent (test runs).
     */
    private function resolveAppVersion(): string
    {
        $candidate = dirname(__DIR__, 4) . '/composer.json';
        if (!is_file($candidate)) {
            return 'dev';
        }
        $raw = @file_get_contents($candidate);
        if (!is_string($raw)) {
            return 'dev';
        }
        $data = json_decode($raw, true);
        if (is_array($data) && isset($data['version']) && is_string($data['version'])) {
            return $data['version'];
        }
        return 'dev';
    }
}
