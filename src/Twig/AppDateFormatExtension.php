<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\SystemSettingsRepository;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\RequestStack;
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
 *   {{ log.createdAt|fa_datetime(true) }} → "31.12.2024 23:59:58" (seconds, audit trails)
 *   {{ log.createdAt|fa_time }}         → "23:59" (time of a timestamp, zone applied; fa_time(true) with seconds)
 *   {{ month|fa_month }}                → "Dezember 2024" (month name in the request locale)
 *
 * Gate 77 (check_date_display.py) rejects any other |date() format in text output.
 *
 * null (or an empty/unparsable value) renders as "—" (the one empty-value placeholder).
 * The settings are read once per request/process cycle.
 */
final class AppDateFormatExtension extends AbstractExtension implements ResetInterface
{
    /** One placeholder for "no value" across the UI. */
    public const string EMPTY = '—';
    public const string DEFAULT_DATE_FORMAT = 'd.m.Y';
    public const string DEFAULT_DATETIME_FORMAT = 'd.m.Y H:i';

    /** @var array<string, string> */
    private array $formats = [];

    private ?\DateTimeZone $timezone = null;

    private bool $timezoneResolved = false;

    public function __construct(
        private readonly SystemSettingsRepository $systemSettingsRepository,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('fa_date', $this->formatDate(...)),
            new TwigFilter('fa_datetime', $this->formatDateTime(...)),
            new TwigFilter('fa_time', $this->formatTime(...)),
            new TwigFilter('fa_month', $this->formatMonth(...)),
        ];
    }

    public function formatDate(mixed $date): string
    {
        $date = $this->toDate($date);
        if ($date === null) {
            return self::EMPTY;
        }

        return $date->format($this->format('date_format', self::DEFAULT_DATE_FORMAT));
    }

    /** @param bool $seconds add seconds to the configured time part (audit trails, token use) */
    public function formatDateTime(mixed $date, bool $seconds = false): string
    {
        $date = $this->zoned($date);
        if ($date === null) {
            return self::EMPTY;
        }

        $format = $this->format('datetime_format', self::DEFAULT_DATETIME_FORMAT);

        return $date->format($seconds ? self::withSeconds($format) : $format);
    }

    /** Time of a timestamp in the application timezone ("23:59", with seconds "23:59:58"). */
    public function formatTime(mixed $date, bool $seconds = false): string
    {
        $date = $this->zoned($date);

        return $date === null ? self::EMPTY : $date->format($seconds ? 'H:i:s' : 'H:i');
    }

    /** Month and year in the request locale ("November 2026"); without intl "11/2026". */
    public function formatMonth(mixed $date): string
    {
        $date = $this->toDate($date);
        if ($date === null) {
            return self::EMPTY;
        }
        if (!class_exists(\IntlDateFormatter::class)) {
            return $date->format('m/Y');
        }
        $locale = $this->requestStack?->getCurrentRequest()?->getLocale() ?? \Locale::getDefault();
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $date->getTimezone(), null, 'LLLL y');
        $text = $formatter->format($date);

        return \is_string($text) ? $text : $date->format('m/Y');
    }

    /** 'd.m.Y H:i' → 'd.m.Y H:i:s'; formats that already show seconds or no minutes stay. */
    private static function withSeconds(string $format): string
    {
        if (str_contains($format, 's') || !str_contains($format, 'i')) {
            return $format;
        }

        return preg_replace('/i(?!.*i)/', 'i:s', $format) ?? $format;
    }

    private function zoned(mixed $date): ?DateTimeInterface
    {
        $date = $this->toDate($date);
        if ($date === null) {
            return null;
        }
        $timezone = $this->timezone();

        return $timezone !== null ? \DateTimeImmutable::createFromInterface($date)->setTimezone($timezone) : $date;
    }

    public function reset(): void
    {
        $this->formats = [];
        $this->timezoneResolved = false;
        $this->timezone = null;
    }

    /**
     * Accepts what Twig's |date accepts (DateTime, date string, Unix timestamp), so
     * templates can switch to fa_date without type checks. Empty or unparsable → null.
     */
    private function toDate(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }
        if (\is_int($value) || (\is_string($value) && ctype_digit($value))) {
            return new \DateTimeImmutable('@' . $value);
        }
        if (\is_string($value) && trim($value) !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
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
