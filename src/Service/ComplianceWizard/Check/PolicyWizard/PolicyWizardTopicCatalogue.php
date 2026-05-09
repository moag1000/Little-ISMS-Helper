<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard;

/**
 * Single source of truth for the 24 ISO 27002:2022 topic-keys that the
 * Policy-Wizard generates a topic-specific policy for.
 *
 * Reference: `docs/plans/policy-wizard/01-iso27001-input.md` §2 (topics 2.1
 * through 2.24). Used both by the {@see PolicyTopicPresentCheck} factory
 * (`config/services.yaml` registers one check instance per topic) and by
 * regression tests pinning the topic-list shape.
 */
final class PolicyWizardTopicCatalogue
{
    /**
     * @var list<string> the 24 ISO 27002 topic-keys, in §2.x order.
     */
    public const ISO27001_TOPICS = [
        'acceptable_use',
        'access_control',
        'information_classification',
        'information_transfer',
        'identity_management',
        'authentication_information',
        'cryptography',
        'backup',
        'logging',
        'patch_management',
        'malware',
        'secure_configuration',
        'network_security',
        'secure_development',
        'supplier_relationships',
        'project_management',
        'privacy_pii',
        'incident_management',
        'continuity',
        'threat_intelligence',
        'mobile_device',
        'asset_management',
        'hr_security',
        'physical_security',
    ];

    /**
     * @return list<string>
     */
    public static function iso27001Topics(): array
    {
        return self::ISO27001_TOPICS;
    }
}
