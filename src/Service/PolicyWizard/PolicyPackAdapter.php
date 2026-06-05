<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\PolicyTemplate;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * F38 — Policy-Pack format adapter.
 *
 * Serialises a set of curated {@see PolicyTemplate}s into a portable, versioned
 * "policy pack" (a JSON manifest + per-template entries with resolved
 * title/body text and the regulatory cross-links), and parses/validates an
 * incoming pack.
 *
 * IMPORTANT — curated-library constraint: this adapter is **export + validate
 * only**. It deliberately does NOT write PolicyTemplates from an imported pack;
 * the template catalogue is curated and seeded via Load / Seed commands, and an
 * arbitrary import would bypass that governance. `parse()` returns a structured,
 * validated view so a future review-queue UI can stage an import behind human
 * approval (see docs) — but the commit step is out of scope here.
 */
final class PolicyPackAdapter
{
    public const string PACK_FORMAT_VERSION = '1.0';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Serialise templates into a policy-pack JSON string.
     *
     * @param iterable<PolicyTemplate> $templates
     */
    public function export(iterable $templates, string $locale = 'de', ?string $packName = null): string
    {
        $entries = [];
        foreach ($templates as $template) {
            $entries[] = $this->serializeTemplate($template, $locale);
        }

        $pack = [
            'pack' => [
                'format_version' => self::PACK_FORMAT_VERSION,
                'name'           => $packName ?? 'policy-pack',
                'locale'         => $locale,
                'entry_count'    => count($entries),
            ],
            'entries' => $entries,
        ];

        return (string) json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Parse + validate a policy-pack JSON string. Read-only — never writes.
     *
     * @return array{valid: bool, errors: list<string>, format_version: ?string, entries: list<array<string, mixed>>}
     */
    public function parse(string $json): array
    {
        $errors = [];

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['valid' => false, 'errors' => ['Invalid JSON: ' . $e->getMessage()], 'format_version' => null, 'entries' => []];
        }

        if (!is_array($decoded)) {
            return ['valid' => false, 'errors' => ['Pack root must be a JSON object.'], 'format_version' => null, 'entries' => []];
        }

        $packMeta = is_array($decoded['pack'] ?? null) ? $decoded['pack'] : [];
        $formatVersion = is_string($packMeta['format_version'] ?? null) ? $packMeta['format_version'] : null;

        if ($formatVersion === null) {
            $errors[] = 'Missing pack.format_version.';
        } elseif (version_compare($formatVersion, self::PACK_FORMAT_VERSION, '>')) {
            $errors[] = sprintf('Unsupported pack format %s (this build supports up to %s).', $formatVersion, self::PACK_FORMAT_VERSION);
        }

        $rawEntries = is_array($decoded['entries'] ?? null) ? $decoded['entries'] : null;
        if ($rawEntries === null) {
            $errors[] = 'Missing or invalid "entries" array.';
            $rawEntries = [];
        }

        $entries = [];
        foreach (array_values($rawEntries) as $i => $entry) {
            if (!is_array($entry)) {
                $errors[] = sprintf('Entry #%d is not an object.', $i + 1);
                continue;
            }
            foreach (['key', 'document_type'] as $required) {
                if (!isset($entry[$required]) || !is_string($entry[$required]) || $entry[$required] === '') {
                    $errors[] = sprintf('Entry #%d is missing required field "%s".', $i + 1, $required);
                }
            }
            $entries[] = $entry;
        }

        return [
            'valid'          => $errors === [],
            'errors'         => $errors,
            'format_version' => $formatVersion,
            'entries'        => $entries,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTemplate(PolicyTemplate $template, string $locale): array
    {
        return [
            'key'           => $template->getKey(),
            'document_type' => $template->getDocumentType(),
            'standard'      => $template->getStandard(),
            'topic'         => $template->getTopic(),
            'norm_ref'      => $template->getNormRef(),
            'version'       => $template->getVersion(),
            'title'         => $this->resolve($template->getTitleTranslationKey(), $locale),
            'body'          => $this->resolve($template->getBodyTranslationKey(), $locale),
            'required_variables'   => $template->getRequiredVariables() ?? [],
            'linked_annex_a'       => $template->getLinkedAnnexAControls() ?? [],
            'linked_bausteine'     => $template->getLinkedBausteine() ?? [],
            'linked_dora_articles' => $template->getLinkedDoraArticles() ?? [],
        ];
    }

    private function resolve(?string $key, string $locale): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        // Policy template title/body live in the translations catalogue; resolve
        // against the requested locale so the pack is self-contained text.
        $translated = $this->translator->trans($key, [], 'policy_templates', $locale);

        // If the key did not resolve, fall back to the raw key so the pack still
        // carries a traceable reference rather than a silent empty string.
        return $translated === $key ? $key : $translated;
    }
}
