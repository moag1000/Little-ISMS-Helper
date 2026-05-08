<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Document;

/**
 * Policy-Wizard W4-C — fuzzy matcher mapping legacy `Document`s to known
 * Policy-Wizard topics.
 *
 * Strategy:
 *   1. Title-based keyword scan (highest confidence) — every topic carries
 *      a list of canonical keywords + per-locale synonyms. A hit returns
 *      `confidence ∈ [0.6, 1.0]` depending on match strength.
 *   2. documentType-based fallback (low confidence ≈ 0.4) — when the title
 *      does not match anything, the category alone hints at top_level for
 *      "policy"/"programme" and at lifecycle/operational topics for
 *      "plan"/"methodology".
 *
 * Returned suggestions are sorted descending by confidence, deduplicated
 * by topic key, capped at {@see self::MAX_SUGGESTIONS} so the Step-0 UI
 * has a predictable dropdown size.
 *
 * No external dependencies — the matcher is a pure function over Document
 * fields, fully unit-testable.
 */
final class ExistingDocumentMatcher
{
    public const MAX_SUGGESTIONS = 5;

    /**
     * Topic-keyword catalogue. Keys are canonical Policy-Wizard topic keys
     * (mirrors `policy_wizard.topic.*` translation domain + DocumentGenerator
     * §8.5 tag taxonomy). Values are case-insensitive substring matches —
     * we walk the document title once per topic.
     *
     * Order matters: more specific keywords go first so e.g. "Access
     * Control" wins over a bare "Control" hit.
     *
     * @var array<string, list<string>>
     */
    private const TOPIC_KEYWORDS = [
        'access_control' => ['access control', 'zugriffskontrolle', 'zugangskontrolle', 'identity management', 'identitätsmanagement'],
        'authentication_information' => ['authentication', 'passwort', 'password', 'mfa', 'authenticator'],
        'cryptography' => ['cryptography', 'kryptographie', 'kryptografie', 'verschlüsselung', 'encryption', 'pki'],
        'backup' => ['backup', 'sicherung', 'datensicherung', 'recovery'],
        'logging' => ['logging', 'monitoring', 'protokollierung', 'event log', 'siem'],
        'patch_management' => ['patch', 'vulnerability', 'schwachstelle'],
        'malware' => ['malware', 'antivirus', 'schadsoftware'],
        'secure_configuration' => ['secure configuration', 'hardening', 'härtung', 'configuration management', 'konfigurationsmanagement'],
        'network_security' => ['network security', 'netzwerksicherheit', 'firewall', 'segmentation', 'segmentierung'],
        'secure_development' => ['secure development', 'sichere entwicklung', 'sdlc', 'devsecops'],
        'supplier_relationships' => ['supplier', 'lieferant', 'third party', 'drittpartei', 'vendor'],
        'project_management' => ['project management', 'projektmanagement', 'projekt'],
        'privacy_pii' => ['privacy', 'datenschutz', 'pii', 'gdpr', 'dsgvo'],
        'incident_management' => ['incident', 'vorfall', 'störung', 'csirt'],
        'continuity' => ['business continuity', 'continuity', 'kontinuität', 'bcm', 'disaster recovery', 'notfallplan'],
        'threat_intelligence' => ['threat intelligence', 'bedrohungsanalyse', 'threat'],
        'mobile_device' => ['mobile device', 'mobiles endgerät', 'remote work', 'home office', 'byod'],
        'asset_management' => ['asset management', 'assetmanagement', 'inventar', 'inventory'],
        'hr_security' => ['hr security', 'personalsicherheit', 'human resources'],
        'physical_security' => ['physical security', 'physische sicherheit', 'gebäude', 'facility'],
        'information_classification' => ['classification', 'klassifizierung', 'kennzeichnung', 'labeling'],
        'information_transfer' => ['information transfer', 'informationsübertragung', 'transfer', 'austausch'],
        'acceptable_use' => ['acceptable use', 'nutzungsrichtlinie', 'usage policy'],
        'top_level' => ['information security policy', 'isms-leitlinie', 'ismsleitlinie', 'top-level', 'sicherheitsleitlinie', 'leitlinie'],
    ];

    /**
     * documentType (Document.category) → low-confidence fallback topic.
     * Only kicks in when the title-based pass returns nothing.
     *
     * @var array<string, string>
     */
    private const TYPE_FALLBACK = [
        'policy' => 'top_level',
        'programme' => 'top_level',
        'plan' => 'continuity',
        'methodology' => 'risk_classification',
    ];

    /**
     * Canonical list of every Policy-Wizard topic key the matcher knows
     * about. Used by Step-0 UI to render the merge / split dropdowns.
     *
     * @return list<string>
     */
    public static function knownTopics(): array
    {
        return array_keys(self::TOPIC_KEYWORDS);
    }

    /**
     * Match a Document to one or more Policy-Wizard topics.
     *
     * @return list<array{topic: string, confidence: float}>
     */
    public function match(Document $document): array
    {
        $title = (string) ($document->getOriginalFilename() ?? $document->getFilename() ?? '');
        $description = (string) ($document->getDescription() ?? '');
        $haystack = mb_strtolower($title . ' ' . $description);
        $haystack = trim($haystack);

        $hits = [];
        if ($haystack !== '') {
            foreach (self::TOPIC_KEYWORDS as $topic => $keywords) {
                $confidence = $this->scoreKeywords($haystack, $keywords);
                if ($confidence > 0.0) {
                    $hits[$topic] = $confidence;
                }
            }
        }

        // Fallback: empty-haystack OR no keyword hit.
        if ($hits === []) {
            $category = $document->getCategory();
            if (is_string($category) && isset(self::TYPE_FALLBACK[$category])) {
                $hits[self::TYPE_FALLBACK[$category]] = 0.4;
            }
        }

        // Sort descending by confidence.
        arsort($hits);

        $suggestions = [];
        foreach ($hits as $topic => $score) {
            $suggestions[] = ['topic' => $topic, 'confidence' => round($score, 2)];
            if (count($suggestions) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }
        return $suggestions;
    }

    /**
     * Score the strongest keyword hit. Long, multi-word keywords score
     * higher than short single-word ones (avoids "control" greedily
     * matching every document).
     *
     * @param list<string> $keywords
     */
    private function scoreKeywords(string $haystack, array $keywords): float
    {
        $best = 0.0;
        foreach ($keywords as $kw) {
            if ($kw === '') {
                continue;
            }
            if (!str_contains($haystack, mb_strtolower($kw))) {
                continue;
            }
            // Multi-word keywords are stronger evidence (≥ 2 spaces → 0.95);
            // single-word keywords land at 0.7. Documents whose title is
            // EXACTLY the keyword get a small bonus to break ties.
            $words = preg_match_all('/\s+/', trim($kw));
            $score = $words >= 1 ? 0.95 : 0.7;
            if ($haystack === mb_strtolower($kw)) {
                $score = min(1.0, $score + 0.05);
            }
            $best = max($best, $score);
        }
        return $best;
    }
}
