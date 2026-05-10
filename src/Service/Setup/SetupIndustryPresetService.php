<?php

declare(strict_types=1);

namespace App\Service\Setup;

use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * V4-EF-1 — Setup-Wizard Industry-Preset Express-Path.
 *
 * Loads curated industry presets from `fixtures/library/presets/*.yaml`
 * and applies them to the SETUP-WIZARD-SESSION (NOT to a tenant — the
 * tenant does not exist yet during setup). Subsequent wizard steps
 * (step7 modules, step8 frameworks) read from these session keys and
 * pre-select accordingly. Step 9+ continues the normal flow.
 *
 * For post-setup application against a real tenant, see
 * {@see \App\Controller\Admin\IndustryPresetController}.
 */
final class SetupIndustryPresetService
{
    public const SESSION_KEY_APPLIED = 'setup_preset_applied';
    public const SESSION_KEY_PRESET_ID = 'setup_preset_id';
    public const SESSION_KEY_MODULES = 'setup_selected_modules';
    public const SESSION_KEY_FRAMEWORKS = 'setup_selected_frameworks';

    /**
     * UI-Metadata for cards (icon + accent variant).
     * Keyed by preset id.
     *
     * @var array<string, array{icon: string, variant: string}>
     */
    private const PRESET_UI = [
        'saas-iso27001' => ['icon' => 'cloud-fill', 'variant' => 'primary'],
        'de-mittelstand-nis2' => ['icon' => 'building-fill', 'variant' => 'info'],
        'health-care-dora' => ['icon' => 'heart-pulse-fill', 'variant' => 'danger'],
        'kritis-energie' => ['icon' => 'lightning-charge-fill', 'variant' => 'warning'],
    ];

    private string $presetDir;

    public function __construct(?string $presetDir = null)
    {
        $this->presetDir = $presetDir ?? \dirname(__DIR__, 3) . '/fixtures/library/presets';
    }

    /**
     * @return array<int, array{
     *   id: string,
     *   name: string,
     *   description: string,
     *   locale: string,
     *   icon: string,
     *   variant: string,
     *   modules_count: int,
     *   frameworks_count: int,
     *   controls_count: int,
     *   documents_count: int,
     *   modules: list<string>,
     *   primary_frameworks: list<string>,
     * }>
     */
    public function listPresets(): array
    {
        $files = glob($this->presetDir . '/*.yaml') ?: [];
        $out = [];
        foreach ($files as $path) {
            $config = Yaml::parseFile($path);
            if (!is_array($config)) {
                continue;
            }
            $id = basename($path, '.yaml');
            $ui = self::PRESET_UI[$id] ?? ['icon' => 'briefcase-fill', 'variant' => 'primary'];
            $primary = [];
            foreach ($config['frameworks'] ?? [] as $fw) {
                if (($fw['priority'] ?? null) === 'primary' && (bool) ($fw['activate'] ?? false)) {
                    $primary[] = (string) $fw['code'];
                }
            }
            $out[] = [
                'id' => $id,
                'name' => (string) ($config['name'] ?? $id),
                'description' => trim((string) ($config['description'] ?? '')),
                'locale' => (string) ($config['locale'] ?? 'en'),
                'icon' => $ui['icon'],
                'variant' => $ui['variant'],
                'modules_count' => count($config['modules'] ?? []),
                'frameworks_count' => count(array_filter(
                    $config['frameworks'] ?? [],
                    static fn(array $f) => (bool) ($f['activate'] ?? false),
                )),
                'controls_count' => count($config['initial_controls'] ?? []),
                'documents_count' => count($config['initial_documents'] ?? []),
                'modules' => array_values(array_map('strval', $config['modules'] ?? [])),
                'primary_frameworks' => $primary,
            ];
        }
        usort($out, static fn(array $a, array $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadPreset(string $presetId): ?array
    {
        if (preg_match('/^[a-z0-9-]+$/', $presetId) !== 1) {
            return null;
        }
        $path = $this->presetDir . '/' . $presetId . '.yaml';
        if (!is_file($path)) {
            return null;
        }
        $config = Yaml::parseFile($path);
        return is_array($config) ? $config : null;
    }

    /**
     * Apply the preset to the wizard session: populate selected modules
     * and selected frameworks, mark applied. Subsequent wizard steps
     * read these keys and skip the manual selection screens.
     *
     * @return array{
     *   modules: list<string>,
     *   frameworks: list<string>,
     * }|null Null when preset id unknown.
     */
    public function applyToSession(string $presetId, SessionInterface $session): ?array
    {
        $config = $this->loadPreset($presetId);
        if ($config === null) {
            return null;
        }

        // Modules — merge with existing required-defaults if any.
        $modules = array_values(array_unique(array_map('strval', $config['modules'] ?? [])));

        // Frameworks — only those flagged activate=true. Always include ISO27001
        // because step8 enforces it server-side anyway.
        $frameworks = [];
        foreach ($config['frameworks'] ?? [] as $fw) {
            if (!(bool) ($fw['activate'] ?? false)) {
                continue;
            }
            $code = (string) ($fw['code'] ?? '');
            if ($code !== '') {
                $frameworks[] = $code;
            }
        }
        if (!in_array('ISO27001', $frameworks, true)) {
            $frameworks[] = 'ISO27001';
        }
        $frameworks = array_values(array_unique($frameworks));

        $session->set(self::SESSION_KEY_MODULES, $modules);
        $session->set(self::SESSION_KEY_FRAMEWORKS, $frameworks);
        $session->set(self::SESSION_KEY_APPLIED, true);
        $session->set(self::SESSION_KEY_PRESET_ID, $presetId);

        return [
            'modules' => $modules,
            'frameworks' => $frameworks,
        ];
    }

    public function clearSession(SessionInterface $session): void
    {
        $session->remove(self::SESSION_KEY_APPLIED);
        $session->remove(self::SESSION_KEY_PRESET_ID);
    }
}
