<?php

declare(strict_types=1);

namespace App\Service\Location;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Static picture of the location map for the PDF situation report: the same
 * Equal Earth projection, land outline (assets/data/world-map.json), crop and
 * pin shapes as assets/controllers/location_map_controller.js, as an SVG data
 * URI dompdf can embed. Pins show the risk layer (circle ok, triangle
 * warning, diamond high, ring critical); the PDF has no interactive layers.
 *
 * Colours are the light-theme FairyAurora tokens as hex, because dompdf
 * renders the SVG without the app's stylesheets.
 */
class LocationMapImage
{
    private const float A1 = 1.340264;
    private const float A2 = -0.081106;
    private const float A3 = 0.000893;
    private const float A4 = 0.003796;
    private const int WIDTH = 1000;
    /** Pin radius by protection requirement 0–5, as on the map. */
    private const array RADIUS = [4, 5, 6, 7, 9, 11];
    /** Image width in px; coordinates are drawn at this scale. */
    private const int PRINT_PX = 700;

    private const array BAND_RANK = [
        'none' => 0, 'ok' => 0, 'low' => 0, 'warn' => 1, 'medium' => 1, 'danger' => 2, 'high' => 2, 'critical' => 3,
    ];
    private const array COLOUR = ['#059669', '#d97706', '#dc2626', '#dc2626'];
    private const string NEUTRAL = '#6d6b92';
    private const string LAND = '#e5e8f4';
    private const string BORDER = '#b9bfd6';
    private const string SEA = '#ffffff';

    public function __construct(
        #[Autowire('%kernel.project_dir%/assets/data/world-map.json')]
        private readonly string $worldFile,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $sites posture rows (lat, lng, riskBand, protection, type)
     * @return string|null data URI, null without placed sites or map data
     */
    public function dataUri(array $sites): ?string
    {
        $svg = $this->svg($sites);

        return $svg !== null ? 'data:image/svg+xml;base64,' . base64_encode($svg) : null;
    }

    /**
     * @param list<array<string, mixed>> $sites
     */
    public function svg(array $sites): ?string
    {
        $points = [];
        foreach ($sites as $site) {
            if (($site['lat'] ?? null) !== null && ($site['lng'] ?? null) !== null) {
                $points[] = ['site' => $site, 'xy' => self::project((float) $site['lng'], (float) $site['lat'])];
            }
        }
        if ($points === [] || !is_file($this->worldFile)) {
            return null;
        }
        $world = json_decode((string) file_get_contents($this->worldFile), true);
        if (!is_array($world) || !is_string($world['land'] ?? null)) {
            return null;
        }

        // dompdf ignores a viewBox offset and does not clip, so the crop is
        // applied to the coordinates: everything is drawn in print pixels.
        $view = self::fitView(array_column($points, 'xy'));
        $s = self::PRINT_PX / $view[2];
        $height = (int) round($view[3] * $s);
        $out = sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d">', self::PRINT_PX, $height);
        $out .= sprintf('<rect x="0" y="0" width="%d" height="%d" fill="%s"/>', self::PRINT_PX, $height, self::SEA);
        $out .= sprintf('<path d="%s" fill="%s" stroke="none"/>', self::cropPath($world['land'], $view, $s), self::LAND);
        if (is_string($world['grenzen'] ?? null)) {
            $out .= sprintf('<path d="%s" fill="none" stroke="%s" stroke-width="0.6"/>', self::cropPath($world['grenzen'], $view, $s), self::BORDER);
        }

        // Highest band on top, as on the map.
        usort($points, static fn (array $a, array $b): int => self::rank($a['site']) <=> self::rank($b['site']));
        foreach ($points as $point) {
            $out .= $this->pin($point['site'], [($point['xy'][0] - $view[0]) * $s, ($point['xy'][1] - $view[1]) * $s]);
        }

        return $out . '</svg>';
    }

    /**
     * @return array{0: float, 1: float}
     */
    public static function project(float $lon, float $lat): array
    {
        [$xmax] = self::raw(180, 0);
        $scale = self::WIDTH / (2 * $xmax);
        [, $top] = self::raw(0, 84);
        [$x, $y] = self::raw($lon, $lat);

        return [($x + $xmax) * $scale, ($top - $y) * $scale];
    }

    /**
     * View box around the points with 30 % margin, at the full map's aspect
     * ratio, clamped to the crop 84° N – 58° S (fitView() in the JS).
     *
     * @param list<array{0: float, 1: float}> $points
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function fitView(array $points): array
    {
        [$xmax] = self::raw(180, 0);
        $scale = self::WIDTH / (2 * $xmax);
        $full = [0.0, 0.0, (float) self::WIDTH, (self::raw(0, 84)[1] - self::raw(0, -58)[1]) * $scale];
        if ($points === []) {
            return $full;
        }
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);
        $minW = 60;
        $w = max(max($xs) - min($xs), $minW) * 1.3;
        $h = max(max($ys) - min($ys), $minW * 0.44) * 1.3;
        $aspect = $full[3] / $full[2];
        if ($h / $w > $aspect) {
            $w = $h / $aspect;
        } else {
            $h = $w * $aspect;
        }
        $w = min(max($w, 12), $full[2]);
        $h = $w * $aspect;
        $x = min(max((min($xs) + max($xs)) / 2 - $w / 2, 0), $full[2] - $w);
        $y = min(max((min($ys) + max($ys)) / 2 - $h / 2, 0), $full[3] - $h);

        return [$x, $y, $w, $h];
    }

    /**
     * @param array<string, mixed> $site
     * @param array{0: float, 1: float} $xy
     */
    private function pin(array $site, array $xy): string
    {
        [$x, $y] = $xy;
        $rank = self::rank($site);
        $colour = ($site['riskBand'] ?? 'none') === 'none' ? self::NEUTRAL : self::COLOUR[$rank];
        $r = self::RADIUS[min(5, max(0, (int) ($site['protection'] ?? 0)))];
        $out = '';
        if ($rank === 3) {
            $out .= sprintf('<circle cx="%s" cy="%s" r="%s" fill="none" stroke="%s" stroke-width="1.2"/>', self::n($x), self::n($y), self::n($r * 2), $colour);
        }
        if (($site['type'] ?? null) === 'region') {
            $out .= sprintf(
                '<circle cx="%s" cy="%s" r="%s" fill="none" stroke="%s" stroke-width="1.2" stroke-dasharray="3 2"/>',
                self::n($x), self::n($y), self::n($r * 2.6), $colour,
            );
        }
        $attrs = sprintf('fill="%s" fill-opacity="0.85" stroke="%s" stroke-width="1.4"', $colour, self::SEA);

        return $out . match ($rank) {
            0 => sprintf('<circle cx="%s" cy="%s" r="%s" %s/>', self::n($x), self::n($y), self::n($r), $attrs),
            1 => sprintf(
                '<path d="M%s %sL%s %sL%s %sZ" %s/>',
                self::n($x), self::n($y - $r * 1.25), self::n($x + $r * 1.15), self::n($y + $r * 0.8), self::n($x - $r * 1.15), self::n($y + $r * 0.8), $attrs,
            ),
            default => sprintf(
                '<path d="M%s %sL%s %sL%s %sL%s %sZ" %s/>',
                self::n($x), self::n($y - $r * 1.3), self::n($x + $r * 1.3), self::n($y), self::n($x), self::n($y + $r * 1.3), self::n($x - $r * 1.3), self::n($y), $attrs,
            ),
        };
    }

    /**
     * Moves and scales an M/L/Z path into the view box and drops the subpaths
     * that lie wholly outside it (keeps the data URI small).
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $view
     */
    private static function cropPath(string $d, array $view, float $s): string
    {
        [$vx, $vy, $vw, $vh] = $view;
        $out = '';
        foreach (preg_split('/(?=M)/', $d, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sub) {
            preg_match_all('/([ML])\s*(-?[\d.]+)[ ,](-?[\d.]+)/', $sub, $m, PREG_SET_ORDER);
            if ($m === []) {
                continue;
            }
            $xs = array_map('floatval', array_column($m, 2));
            $ys = array_map('floatval', array_column($m, 3));
            if (max($xs) < $vx || min($xs) > $vx + $vw || max($ys) < $vy || min($ys) > $vy + $vh) {
                continue;
            }
            foreach ($m as $i => $cmd) {
                $out .= $cmd[1] . self::n(($xs[$i] - $vx) * $s) . ' ' . self::n(($ys[$i] - $vy) * $s);
            }
            $out .= str_contains($sub, 'Z') ? 'Z' : '';
        }

        return $out;
    }

    /** @param array<string, mixed> $site */
    private static function rank(array $site): int
    {
        return self::BAND_RANK[$site['riskBand'] ?? 'none'] ?? 0;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function raw(float $lon, float $lat): array
    {
        $em = sqrt(3) / 2;
        $l = deg2rad($lon);
        $t = asin($em * sin(deg2rad($lat)));
        $t2 = $t * $t;
        $t6 = $t2 * $t2 * $t2;

        return [
            $l * cos($t) / ($em * (self::A1 + 3 * self::A2 * $t2 + $t6 * (7 * self::A3 + 9 * self::A4 * $t2))),
            $t * (self::A1 + self::A2 * $t2 + $t6 * (self::A3 + self::A4 * $t2)),
        ];
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
