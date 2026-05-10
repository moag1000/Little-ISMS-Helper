<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SystemSettingsRepository;

/**
 * Audit V3 C3 — Central toggle gate for the 5 Auto-Reactions:
 *   - Auto-DPIA-Vorschlag (ProcessingActivity)
 *   - Auto-Training-Assignment (User onPostPersist)
 *   - Auto-Risk-Skeleton (Vulnerability with CVSS >= 7.0)
 *   - Auto-CorrectiveAction (AuditFinding major/critical)
 *   - Auto-Acknowledgement-Campaign (Document approved + requiresAck)
 *
 * Each toggle defaults to TRUE. Admins can opt out per-tenant via
 * SystemSettings (category=auto_reactions). The /admin/settings UI
 * surfaces these via the shared SystemSettings page.
 */
class AutoReactionService
{
    public const CATEGORY = 'auto_reactions';

    public const KEY_DPIA_SUGGEST       = 'dpia_suggest_on_pa_high_risk';
    public const KEY_TRAINING_ASSIGN    = 'training_assign_on_user_create';
    public const KEY_RISK_SKELETON      = 'risk_skeleton_on_high_cvss';
    public const KEY_CA_ON_FINDING      = 'corrective_action_on_major_finding';
    public const KEY_ACK_CAMPAIGN       = 'acknowledgement_campaign_on_approval';

    public const ALL_KEYS = [
        self::KEY_DPIA_SUGGEST,
        self::KEY_TRAINING_ASSIGN,
        self::KEY_RISK_SKELETON,
        self::KEY_CA_ON_FINDING,
        self::KEY_ACK_CAMPAIGN,
    ];

    public function __construct(
        private readonly SystemSettingsRepository $settingsRepo,
    ) {
    }

    public function isEnabled(string $key): bool
    {
        // Default: ON for every toggle.
        $value = $this->settingsRepo->getSetting(self::CATEGORY, $key, true);
        return (bool) $value;
    }

    /**
     * @return array<string, bool>
     */
    public function allToggles(): array
    {
        $out = [];
        foreach (self::ALL_KEYS as $key) {
            $out[$key] = $this->isEnabled($key);
        }
        return $out;
    }
}
