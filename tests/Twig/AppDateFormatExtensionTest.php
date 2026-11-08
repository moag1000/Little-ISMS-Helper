<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Repository\SystemSettingsRepository;
use App\Twig\AppDateFormatExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppDateFormatExtensionTest extends TestCase
{
    private function extension(array $settings, ?int &$calls = null): AppDateFormatExtension
    {
        $repo = $this->createStub(SystemSettingsRepository::class);
        $repo->method('getSetting')->willReturnCallback(
            static function (string $category, string $key, mixed $default = null) use ($settings, &$calls): mixed {
                $calls++;

                return $settings[$category][$key] ?? $default;
            }
        );

        return new AppDateFormatExtension($repo);
    }

    #[Test]
    public function usesDefaultsWhenNothingStored(): void
    {
        $ext = $this->extension([]);
        $date = new \DateTimeImmutable('2024-12-31 23:59:58');

        self::assertSame('31.12.2024', $ext->formatDate($date));
        self::assertSame('31.12.2024 23:59', $ext->formatDateTime($date));
    }

    #[Test]
    public function usesStoredFormats(): void
    {
        $ext = $this->extension(['application' => ['date_format' => 'Y-m-d', 'datetime_format' => 'Y-m-d H:i:s']]);
        $date = new \DateTime('2024-12-31 23:59:58');

        self::assertSame('2024-12-31', $ext->formatDate($date));
        self::assertSame('2024-12-31 23:59:58', $ext->formatDateTime($date));
    }

    #[Test]
    public function nullRendersDash(): void
    {
        $ext = $this->extension([]);

        self::assertSame('—', $ext->formatDate(null));
        self::assertSame('—', $ext->formatDateTime(null));
        self::assertSame('—', $ext->formatDate(''));
        self::assertSame('—', $ext->formatDate('kein Datum'));
    }

    #[Test]
    public function acceptsWhatTwigDateAccepts(): void
    {
        $ext = $this->extension([]);

        self::assertSame('31.12.2024', $ext->formatDate('2024-12-31'));
        self::assertSame('31.12.2024 23:59', $ext->formatDateTime('2024-12-31 23:59:00'));
        self::assertSame('01.01.1970', $ext->formatDate(0));
    }

    #[Test]
    public function emptyStoredValueFallsBackToDefault(): void
    {
        $ext = $this->extension(['application' => ['date_format' => '']]);

        self::assertSame('01.02.2024', $ext->formatDate(new \DateTimeImmutable('2024-02-01')));
    }

    #[Test]
    public function settingIsCachedUntilReset(): void
    {
        $calls = 0;
        $ext = $this->extension([], $calls);
        $date = new \DateTimeImmutable('2024-02-01');

        $ext->formatDate($date);
        $ext->formatDate($date);
        self::assertSame(1, $calls);

        $ext->reset();
        $ext->formatDate($date);
        self::assertSame(2, $calls);
    }

    #[Test]
    public function registersFilters(): void
    {
        $names = array_map(static fn ($f) => $f->getName(), $this->extension([])->getFilters());

        self::assertSame(['fa_date', 'fa_datetime', 'fa_time', 'fa_month'], $names);
    }

    #[Test]
    public function dateTimeIsShownInConfiguredTimezoneButDateIsNot(): void
    {
        $ext = $this->extension(['application' => ['timezone' => 'Europe/Berlin']]);
        $utc = new \DateTimeImmutable('2024-12-31 23:30:00', new \DateTimeZone('UTC'));

        self::assertSame('01.01.2025 00:30', $ext->formatDateTime($utc));
        self::assertSame('31.12.2024', $ext->formatDate($utc));
    }

    #[Test]
    public function secondsTimeAndMonth(): void
    {
        $ext = $this->extension(['application' => ['timezone' => 'Europe/Berlin']]);
        $utc = new \DateTimeImmutable('2024-12-31 23:30:15', new \DateTimeZone('UTC'));

        self::assertSame('01.01.2025 00:30:15', $ext->formatDateTime($utc, true));
        self::assertSame('00:30', $ext->formatTime($utc));
        self::assertSame('00:30:15', $ext->formatTime($utc, true));
        self::assertSame('—', $ext->formatTime(null));
        // A time of day stored without a zone (TIME column) is shown as stored.
        self::assertSame('23:30', $ext->formatTime($utc, false, false));

        $ymd = $this->extension(['application' => ['datetime_format' => 'Y-m-d H:i:s']]);
        self::assertSame('2024-12-31 23:30:15', $ymd->formatDateTime($utc, true));

        if (class_exists(\IntlDateFormatter::class)) {
            $previous = \Locale::getDefault();
            \Locale::setDefault('de');
            try {
                self::assertSame('Dezember 2024', $this->extension([])->formatMonth(new \DateTimeImmutable('2024-12-01')));
            } finally {
                \Locale::setDefault($previous);
            }
        }
        self::assertSame('—', $ext->formatMonth(null));
    }
}
