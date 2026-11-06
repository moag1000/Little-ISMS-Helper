<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where the prose of a policy standard lives: one translation domain per
 * batch file (`translations/policy_<…>.{de,en}.yaml`). Single source for the
 * generator, the Twig `policy_title()` function and the policy-pack export;
 * PolicyTranslationDomainsTest fails when a policy_* file is in no list.
 */
final class PolicyTranslationDomains
{
    /** @var array<string, list<string>> */
    public const array BY_STANDARD = [
        'gdpr'      => ['policy_privacy_batch1', 'policy_privacy_sections'],
        'iso27001'  => ['policy_iso27001', 'policy_iso27001_batch2', 'policy_iso27001_batch3', 'policy_iso27001_batch4', 'policy_iso27001_batch5'],
        'iso27701'  => ['policy_iso27701'],
        'bsi'       => ['policy_bsi', 'policy_bsi_batch1', 'policy_bsi_batch2', 'policy_bsi_batch3', 'policy_bsi_batch4', 'policy_bsi_batch5'],
        'bcm'       => ['policy_bcm', 'policy_bcm_batch1', 'policy_bcm_batch2'],
        'dora'      => ['policy_dora'],
        'soc2'      => ['policy_soc2'],
        'tisax'     => ['policy_tisax'],
        'nis2'      => ['policy_nis2'],
        'c5'        => ['policy_c5'],
        'gspp'      => ['policy_gspp'],
        'kritis'    => ['policy_kritis'],
    ];

    /** @return list<string> */
    public static function for(?string $standard): array
    {
        if ($standard === null || $standard === '') {
            return [];
        }

        return array_values(array_unique([...(self::BY_STANDARD[$standard] ?? []), 'policy_' . $standard]));
    }

    /**
     * First translation of $key in the standard's domains, or null when none has it.
     */
    public static function translate(TranslatorInterface $translator, string $key, ?string $standard, ?string $locale = null): ?string
    {
        foreach (self::for($standard) as $domain) {
            $resolved = $translator->trans($key, [], $domain, $locale);
            if ($resolved !== $key) {
                return $resolved;
            }
        }

        return null;
    }
}
