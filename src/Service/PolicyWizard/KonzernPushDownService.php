<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Repository\TenantPolicySettingRepository;
use App\Service\AuditLogger;
use App\Service\TenantSettingResolver\OverrideMode;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Policy-Wizard W3-B — Konzern push-down service.
 *
 * Implements `docs/plans/policy-wizard/05-architecture.md` §7.4:
 * when a Konzern-CISO updates a `TenantPolicySetting` at the parent
 * level, this service walks the descendant tree, flags subsidiaries
 * whose stored value violates the new override-mode rule (e.g. parent
 * raised crypto floor 128→256, descendant has 192), persists a
 * `_meta.settings_drift_detected` marker, emits a Tier-1 Alva-Hint via
 * the SettingsDriftRule, and writes a structured `KonzernPushDown`
 * audit-log event.
 *
 * The propagation is idempotent: re-running with an unchanged value
 * leaves drift markers untouched and does not re-emit hints.
 */
final class KonzernPushDownService
{
    /**
     * Override-mode catalogue for known Policy-Wizard setting keys.
     * Mirrors `HierarchyOverrideValidator::SETTING_MAP` plus the
     * additional Konzern-default keys mentioned in §7.3.
     *
     * @var array<string, OverrideMode>
     */
    private const KEY_OVERRIDE_MODE = [
        'risk.appetite_tier' => OverrideMode::CeilingOnly,
        'policy.review_interval_months' => OverrideMode::CeilingOnly,
        'backup.rpo_hours' => OverrideMode::CeilingOnly,
        'crypto.minimum_key_length' => OverrideMode::FloorOnly,
        'gdpr.scope_flag' => OverrideMode::ForbiddenToRelax,
        'approval.top_management_chain' => OverrideMode::ForbiddenToChange,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantPolicySettingRepository $settingRepository,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Walk the descendants of $konzern and flag any tenant whose own
     * value for $settingKey violates the override-mode rule given the
     * new parent value. Idempotent.
     *
     * @return array{
     *   affected_subsidiaries: list<int>,
     *   alva_hints_emitted: int,
     * }
     */
    public function propagate(Tenant $konzern, string $settingKey, mixed $newValue): array
    {
        $mode = self::KEY_OVERRIDE_MODE[$settingKey] ?? OverrideMode::Free;
        if ($mode === OverrideMode::Free) {
            // Free settings cannot drift — nothing to propagate.
            return ['affected_subsidiaries' => [], 'alva_hints_emitted' => 0];
        }

        $descendants = $konzern->getAllSubsidiaries();
        if ($descendants === []) {
            return ['affected_subsidiaries' => [], 'alva_hints_emitted' => 0];
        }

        $affected = [];
        $hintsEmitted = 0;
        $oldValue = $this->readPersistedKonzernValue($konzern, $settingKey);

        foreach ($descendants as $descendant) {
            $descendantId = $descendant->getId();
            if ($descendantId === null) {
                continue;
            }

            $setting = $this->settingRepository->findOneByTenantAndKey($descendant, $settingKey);
            if (!$setting instanceof TenantPolicySetting) {
                continue;
            }

            $childValue = $setting->getValue();
            if ($childValue === null) {
                continue;
            }

            // Strip any existing _meta wrapping for the violation check.
            [$pureChildValue, $existingMeta] = $this->splitValueAndMeta($childValue);

            if (!$this->violates($mode, $newValue, $pureChildValue)) {
                // Conforming descendant — clear stale drift marker if present.
                if (isset($existingMeta['settings_drift_detected']) && $existingMeta['settings_drift_detected'] === true) {
                    unset($existingMeta['settings_drift_detected'], $existingMeta['drift_parent_value'], $existingMeta['drift_detected_at']);
                    $setting->setValue($this->repackValueAndMeta($pureChildValue, $existingMeta));
                    $setting->setUpdatedAt(new DateTimeImmutable());
                }
                continue;
            }

            $alreadyFlagged = ($existingMeta['settings_drift_detected'] ?? false) === true
                && ($existingMeta['drift_parent_value'] ?? null) === $newValue;

            $existingMeta['settings_drift_detected'] = true;
            $existingMeta['drift_parent_value'] = $newValue;
            $existingMeta['drift_detected_at'] = (new DateTimeImmutable())->format(\DateTimeInterface::ATOM);

            $setting->setValue($this->repackValueAndMeta($pureChildValue, $existingMeta));
            $setting->setUpdatedAt(new DateTimeImmutable());

            $affected[] = $descendantId;

            if (!$alreadyFlagged) {
                $hintsEmitted++;
            }
        }

        if ($affected !== []) {
            $this->entityManager->flush();
        }

        $isInitialPropagation = $hintsEmitted > 0;
        if ($isInitialPropagation) {
            $this->auditLogger->logCustom(
                action: 'KonzernPushDown',
                entityType: 'TenantPolicySetting',
                entityId: $konzern->getId(),
                oldValues: ['value' => $oldValue],
                newValues: [
                    'key' => $settingKey,
                    'value' => $newValue,
                    'override_mode' => $mode->value,
                    'affected_tenants' => $affected,
                ],
                description: sprintf(
                    'Konzern-Push-Down: setting "%s" changed; %d subsidiaries flagged with drift.',
                    $settingKey,
                    count($affected),
                ),
            );
        }

        $this->logger->info('KonzernPushDownService propagation complete', [
            'konzern_id' => $konzern->getId(),
            'setting_key' => $settingKey,
            'mode' => $mode->value,
            'affected_count' => count($affected),
            'alva_hints_emitted' => $hintsEmitted,
        ]);

        return [
            'affected_subsidiaries' => $affected,
            'alva_hints_emitted' => $hintsEmitted,
        ];
    }

    /**
     * Split a stored setting value into (pureValue, meta). Meta lives
     * under the reserved `_meta` key when the stored value is an
     * associative array; otherwise meta is empty and the value passes
     * through unchanged.
     *
     * @return array{0: mixed, 1: array<string, mixed>}
     */
    private function splitValueAndMeta(mixed $value): array
    {
        if (is_array($value) && array_key_exists('_meta', $value) && is_array($value['_meta'])) {
            $meta = $value['_meta'];
            $pure = $value;
            unset($pure['_meta']);
            // If the only remaining key is a `value` wrapper, unwrap it
            // so callers can compare raw scalars. We keep arrays as-is
            // when there are multiple keys.
            if (array_keys($pure) === ['value']) {
                $pure = $pure['value'];
            }
            return [$pure, $meta];
        }
        return [$value, []];
    }

    /**
     * Inverse of splitValueAndMeta. Re-attaches the meta map under
     * `_meta`. Wraps scalars in `{value: ..., _meta: ...}` so the
     * structure is queryable.
     *
     * @param array<string, mixed> $meta
     */
    private function repackValueAndMeta(mixed $pureValue, array $meta): mixed
    {
        if ($meta === []) {
            return $pureValue;
        }
        if (is_array($pureValue) && !array_is_list($pureValue)) {
            $pureValue['_meta'] = $meta;
            return $pureValue;
        }
        return [
            'value' => $pureValue,
            '_meta' => $meta,
        ];
    }

    private function readPersistedKonzernValue(Tenant $konzern, string $settingKey): mixed
    {
        $setting = $this->settingRepository->findOneByTenantAndKey($konzern, $settingKey);
        if (!$setting instanceof TenantPolicySetting) {
            return null;
        }
        [$pure, ] = $this->splitValueAndMeta($setting->getValue());
        return $pure;
    }

    /**
     * Pure check: does the descendant's $candidate value violate the
     * override-mode against the new $parent value?
     *
     * Mirrors {@see HierarchyOverrideValidator::violates} so the
     * push-down detector and the wizard-time gate stay in lock-step.
     */
    private function violates(OverrideMode $mode, mixed $parent, mixed $candidate): bool
    {
        if ($parent === null) {
            return false;
        }

        return match ($mode) {
            OverrideMode::ForbiddenToChange => !$this->valuesEqual($parent, $candidate),
            OverrideMode::ForbiddenToRelax => $this->isLooser($parent, $candidate),
            OverrideMode::FloorOnly => is_numeric($parent) && is_numeric($candidate)
                ? (float) $candidate < (float) $parent
                : (is_bool($parent) && $parent === true && $candidate === false),
            OverrideMode::CeilingOnly => is_numeric($parent) && is_numeric($candidate)
                ? (float) $candidate > (float) $parent
                : (is_bool($parent) && $parent === false && $candidate === true),
            OverrideMode::Free => false,
        };
    }

    private function isLooser(mixed $parent, mixed $candidate): bool
    {
        if (is_bool($parent) || is_bool($candidate)) {
            return ((bool) $parent) === true && ((bool) $candidate) === false;
        }
        if (is_numeric($parent) && is_numeric($candidate)) {
            return (float) $candidate < (float) $parent;
        }
        return !$this->valuesEqual($parent, $candidate);
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        return $a === $b;
    }
}
