<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\SystemSettingsRepository;
use DateTimeInterface;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Formats dates with the admin-configured formats from /admin/settings/application
 * (SystemSettings application.date_format / application.datetime_format).
 * fa_datetime also converts to application.timezone; fa_date does not, because
 * date-only columns are midnight values and a zone shift would change the day.
 *
 * Usage:
 *   {{ entity.createdAt|fa_date }}      → "31.12.2024" (default 'd.m.Y')
 *   {{ entity.createdAt|fa_datetime }}  → "31.12.2024 23:59" (default 'd.m.Y H:i')
 *
 * null renders as "-" (same convention as {@see LocaleDateExtension}).
 * The settings are read once per request/process cycle.
 */
final class AppDateFormatExtension extends AbstractExtension implements ResetInterface
{
    public const string DEFAULT_DATE_FORMAT = 'd.m.Y';
    public const string DEFAULT_DATETIME_FORMAT = 'd.m.Y H:i';

    /** @var array<string, string> */
    private array $formats = [];

    private ?\DateTimeZone $timezone = null;

    private bool $timezoneResolved = false;

    public function __construct(
        private readonly SystemSettingsRepository $systemSettingsRepository,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('fa_date', $this->formatDate(...)),
            new TwigFilter('fa_datetime', $this->formatDateTime(...)),
        ];
    }

    public function formatDate(?DateTimeInterface $date): string
    {
        if ($date === null) {
            return '-';
        }

        return $date->format($this->format('date_format', self::DEFAULT_DATE_FORMAT));
    }

    public function formatDateTime(?DateTimeInterface $date): string
    {
        if ($date === null) {
            return '-';
        }

        $timezone = $this->timezone();
        if ($timezone !== null) {
            $date = \DateTimeImmutable::createFromInterface($date)->setTimezone($timezone);
        }

        return $date->format($this->format('datetime_format', self::DEFAULT_DATETIME_FORMAT));
    }

    public function reset(): void
    {
        $this->formats = [];
        $this->timezoneResolved = false;
        $this->timezone = null;
    }

    private function timezone(): ?\DateTimeZone
    {
        if (!$this->timezoneResolved) {
            $this->timezoneResolved = true;
            try {
                $value = $this->systemSettingsRepository->getSetting('application', 'timezone', null);
                $this->timezone = (\is_string($value) && $value !== '') ? new \DateTimeZone($value) : null;
            } catch (\Throwable) {
                $this->timezone = null;
            }
        }

        return $this->timezone;
    }

    private function format(string $key, string $default): string
    {
        if (!isset($this->formats[$key])) {
            try {
                $value = $this->systemSettingsRepository->getSetting('application', $key, $default);
            } catch (\Throwable) {
                $value = $default;
            }
            $this->formats[$key] = (\is_string($value) && $value !== '') ? $value : $default;
        }

        return $this->formats[$key];
    }
}
