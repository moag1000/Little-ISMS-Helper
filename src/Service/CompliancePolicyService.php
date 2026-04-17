<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SystemSettings;
use App\Repository\SystemSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tenant-agnostic compliance-policy settings.
 * Values are read from system_settings (category = 'compliance') with
 * fallback to the YAML defaults injected via constructor parameters.
 *
 * Admins can edit the values under /admin/compliance/settings (UI) or via
 * `set()` which also logs the change to the audit log.
 */
class CompliancePolicyService
{
    public const CATEGORY = 'compliance';

    public const KEY_MIN_COMMENT_LENGTH = 'review.min_comment_length';
    public const KEY_MIN_OVERRIDE_REASON_LENGTH = 'review.min_override_reason_length';
    public const KEY_SIGNIFICANT_CHANGE_THRESHOLD = 'source_update.significant_change_threshold';
    public const KEY_FOUR_EYES_EXPIRY_DAYS = 'four_eyes.expiry_days';
    public const KEY_PORTFOLIO_GREEN = 'portfolio.threshold_green';
    public const KEY_PORTFOLIO_YELLOW = 'portfolio.threshold_yellow';
    public const KEY_REUSE_DAYS_PER_REQUIREMENT = 'reuse_estimation.days_per_requirement';
    public const KEY_IMPORT_MAX_UPLOAD_MB = 'import.max_upload_mb';
    public const KEY_IMPORT_FOUR_EYES_ROW_THRESHOLD = 'import.four_eyes_row_threshold';
    public const KEY_INHERITANCE_BADGE_POLL = 'ui.inheritance_badge_poll_seconds';
    public const KEY_QUICK_WIN_EFFORT_PERCENTILE = 'gap_report.quick_win_effort_percentile';
    public const KEY_QUICK_WIN_MIN_GAP_PERCENT = 'gap_report.quick_win_min_gap_percent';
    public const KEY_INHERITANCE_ENABLED = 'mapping_inheritance.enabled';

    /** @var array<string, mixed> runtime cache to spare repeated DB hits */
    private array $cache = [];

    public function __construct(
        private readonly SystemSettingsRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        /** @var array<string, mixed> Defaults from services.yaml */
        private readonly array $defaults = [],
    ) {
    }

    public function getInt(string $key, int $fallback = 0): int
    {
        $v = $this->read($key);
        if ($v === null) {
            $yamlDefault = $this->defaults[$key] ?? null;
            return is_numeric($yamlDefault) ? (int) $yamlDefault : $fallback;
        }
        return (int) $v;
    }

    public function getFloat(string $key, float $fallback = 0.0): float
    {
        $v = $this->read($key);
        if ($v === null) {
            $yamlDefault = $this->defaults[$key] ?? null;
            return is_numeric($yamlDefault) ? (float) $yamlDefault : $fallback;
        }
        return (float) $v;
    }

    public function getBool(string $key, bool $fallback = false): bool
    {
        $v = $this->read($key);
        if ($v === null) {
            $yamlDefault = $this->defaults[$key] ?? null;
            return $yamlDefault === null ? $fallback : (bool) $yamlDefault;
        }
        return (bool) $v;
    }

    public function set(string $key, mixed $value, ?string $actor = null): void
    {
        $entity = $this->repository->findOneBy([
            'category' => self::CATEGORY,
            'key' => $key,
        ]);
        $previous = $entity?->getValue();

        if ($entity === null) {
            $entity = (new SystemSettings())
                ->setCategory(self::CATEGORY)
                ->setKey($key);
            $this->entityManager->persist($entity);
        }
        $entity->setValue($value);
        $this->entityManager->flush();

        $this->cache[$key] = $value;

        $this->auditLogger->logCustom(
            'compliance.policy.updated',
            'SystemSettings',
            $entity->getId(),
            ['value' => $previous],
            ['value' => $value],
            sprintf('Compliance policy %s updated by %s', $key, $actor ?? 'system'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $db = [];
        foreach ($this->repository->findBy(['category' => self::CATEGORY]) as $setting) {
            /** @var SystemSettings $setting */
            $db[(string) $setting->getKey()] = $setting->getValue();
        }
        return array_merge($this->defaults, $db);
    }

    public function defaults(): array
    {
        return $this->defaults;
    }

    private function read(string $key): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        $setting = $this->repository->findOneBy([
            'category' => self::CATEGORY,
            'key' => $key,
        ]);
        $value = $setting?->getValue();
        $this->cache[$key] = $value;
        return $value;
    }
}
