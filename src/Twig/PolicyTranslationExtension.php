<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Resolves translation keys whose home-domain depends on the standard +
 * batch-file the entity belongs to.
 *
 * PolicyTemplate stores `titleTranslationKey` as e.g.
 * `policy.gdpr.dpia_methodology.v1.title`. The actual translation
 * lives in `translations/policy_<standard>_batch<n>.{de,en}.yaml`.
 * Symfony trans() needs an explicit domain — defaulting to messages
 * (or trans_default_domain) returns the raw key.
 *
 * `policy_title(key, standard)` walks the candidate domains for that
 * standard and returns the first non-key match. Falls back to a
 * humanised topic-name when no domain holds the key (covers Junior-
 * ISB Step 6 Lifecycle table where the per-template approver-override
 * row would otherwise render the raw key path).
 */
final class PolicyTranslationExtension extends AbstractExtension
{
    /**
     * Per-standard candidate domain list. Iterated in order until a
     * domain returns a translation for the key. Add new batches here
     * as they ship.
     *
     * @var array<string, list<string>>
     */
    private const DOMAINS_BY_STANDARD = [
        'gdpr'      => ['policy_privacy_batch1', 'policy_privacy_sections'],
        'iso27001'  => ['policy_iso27001', 'policy_iso27001_batch2', 'policy_iso27001_batch3', 'policy_iso27001_batch4'],
        'bsi'       => ['policy_bsi_batch1', 'policy_bsi_batch2', 'policy_bsi_batch3'],
        'bcm'       => ['policy_bcm_batch1', 'policy_bcm_batch2'],
        'dora'      => ['policy_dora'],
    ];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('policy_title', $this->resolveTitle(...)),
        ];
    }

    /**
     * Resolve a `policy.<standard>.<topic>.v1.title` key to its
     * translated text, or a humanised topic-fallback when the key
     * is not authored in any candidate domain.
     */
    public function resolveTitle(?string $key, ?string $standard, ?string $topicFallback = null): string
    {
        if ($key === null || $key === '') {
            return $topicFallback !== null ? $this->humanise($topicFallback) : '—';
        }

        $candidates = self::DOMAINS_BY_STANDARD[$standard] ?? [];
        foreach ($candidates as $domain) {
            $resolved = $this->translator->trans($key, [], $domain);
            if ($resolved !== $key) {
                return $resolved;
            }
        }

        // Last-resort: humanise the topic segment from the key path.
        // `policy.gdpr.dpia_methodology.v1.title` → `DPIA Methodology`.
        $parts = explode('.', $key);
        $topic = $parts[2] ?? ($topicFallback ?? $key);
        return $this->humanise($topic);
    }

    private function humanise(string $snake): string
    {
        return ucwords(str_replace('_', ' ', $snake));
    }
}
