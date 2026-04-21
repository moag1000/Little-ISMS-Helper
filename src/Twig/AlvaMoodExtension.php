<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * FairyAurora v3.0 — Alva-Mood Twig Global
 *
 * Stellt den aktuellen Mood als Twig-Global `alva_mood` zur Verfuegung.
 *
 * Regelwerk (Plan § 6):
 *   1. Flash-Bag-Wert 'alva_mood' (Page-Load-Einmalwirkung, z. B. Audit-Pass → celebrating)
 *   2. Session-Attribut 'alva_mood' (lang-laufender Kontext, z. B. Import läuft → working)
 *   3. Nachts (>= 22:00 lokal, < 6:00 lokal) → sleeping (Easter-Egg)
 *   4. Fallback: idle
 *
 * Weitere Regeln (kritische Findings > 0 → warning) werden später von
 * konkreten Services via Session/Flash gesetzt, damit keine DB-Query
 * in jedem Render steckt.
 */
class AlvaMoodExtension extends AbstractExtension implements GlobalsInterface
{
    private const VALID_MOODS = [
        'idle', 'happy', 'thinking', 'focused', 'working',
        'scanning', 'warning', 'celebrating', 'sleeping',
    ];

    public function __construct(
        private readonly RequestStack $requestStack
    ) {
    }

    public function getGlobals(): array
    {
        return [
            'alva_mood' => $this->resolveMood(),
        ];
    }

    public function resolveMood(): string
    {
        $session = $this->getSession();

        if ($session !== null) {
            $flashBag = $session->getFlashBag();
            if ($flashBag->has('alva_mood')) {
                $flashes = $flashBag->get('alva_mood');
                $candidate = is_array($flashes) ? (string) ($flashes[0] ?? '') : (string) $flashes;
                if (in_array($candidate, self::VALID_MOODS, true)) {
                    return $candidate;
                }
            }
            $attr = (string) $session->get('alva_mood', '');
            if (in_array($attr, self::VALID_MOODS, true)) {
                return $attr;
            }
        }

        $hour = (int) date('G');
        if ($hour >= 22 || $hour < 6) {
            return 'sleeping';
        }

        return 'idle';
    }

    private function getSession(): ?\Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }
        return $request->getSession();
    }
}
