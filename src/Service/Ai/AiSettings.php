<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Tenant;
use App\Repository\SystemSettingsRepository;
use App\Service\Sso\SsoSecretEncryption;
use Doctrine\ORM\EntityManagerInterface;

/**
 * AI settings in two layers (Paket 5):
 *  - instance (operator, SUPER_ADMIN): the local model — any URL, internal
 *    addresses allowed, because the operator runs the server anyway;
 *  - organisation (ROLE_ADMIN, Tenant.settings['ai']): off (default) /
 *    local / external (public https endpoint such as the Mistral API, own
 *    key), whether personal data may leave the house, which use cases run.
 * Keys are stored encrypted (instance: SystemSettings, organisation: AEAD).
 */
class AiSettings
{
    public const string MODE_OFF = 'off';
    public const string MODE_LOCAL = 'local';
    public const string MODE_EXTERNAL = 'external';
    public const array MODES = [self::MODE_OFF, self::MODE_LOCAL, self::MODE_EXTERNAL];

    public const string DEFAULT_LOCAL_ENDPOINT = 'http://localhost:11434/v1';
    public const string DEFAULT_LOCAL_MODEL = 'mistral';
    public const string DEFAULT_EXTERNAL_ENDPOINT = 'https://api.mistral.ai/v1';
    public const string DEFAULT_EXTERNAL_MODEL = 'mistral-small-latest';

    private const string CATEGORY = 'ai';
    private const string TENANT_KEY = 'ai';

    public function __construct(
        private readonly SystemSettingsRepository $systemSettings,
        private readonly SsoSecretEncryption $encryption,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array{endpoint: string, model: string, has_key: bool, configured: bool} */
    public function instance(): array
    {
        $endpoint = (string) $this->systemSettings->getSetting(self::CATEGORY, 'local_endpoint', '');

        return [
            'endpoint' => $endpoint !== '' ? $endpoint : self::DEFAULT_LOCAL_ENDPOINT,
            'model' => (string) ($this->systemSettings->getSetting(self::CATEGORY, 'local_model', '') ?: self::DEFAULT_LOCAL_MODEL),
            'has_key' => (string) $this->systemSettings->getSetting(self::CATEGORY, 'local_api_key', '') !== '',
            'configured' => $endpoint !== '',
        ];
    }

    public function saveInstance(string $endpoint, string $model, ?string $apiKey, bool $clearKey, string $updatedBy): void
    {
        $this->systemSettings->setSetting(self::CATEGORY, 'local_endpoint', $endpoint, false, 'Local AI endpoint (OpenAI-compatible)', $updatedBy);
        $this->systemSettings->setSetting(self::CATEGORY, 'local_model', $model, false, 'Local AI model', $updatedBy);
        if ($clearKey) {
            $this->systemSettings->setSetting(self::CATEGORY, 'local_api_key', '', false, 'Local AI API key', $updatedBy);
        } elseif ($apiKey !== null && $apiKey !== '') {
            $this->systemSettings->setSetting(self::CATEGORY, 'local_api_key', $apiKey, true, 'Local AI API key', $updatedBy);
        }
    }

    /**
     * @return array{mode: string, endpoint: string, model: string, has_key: bool,
     *               allow_personal_data: bool, disabled_use_cases: list<string>}
     */
    public function tenant(Tenant $tenant): array
    {
        $raw = (array) (($tenant->getSettings() ?? [])[self::TENANT_KEY] ?? []);
        $mode = in_array($raw['mode'] ?? null, self::MODES, true) ? $raw['mode'] : self::MODE_OFF;

        return [
            'mode' => $mode,
            'endpoint' => (string) ($raw['endpoint'] ?? '') ?: self::DEFAULT_EXTERNAL_ENDPOINT,
            'model' => (string) ($raw['model'] ?? '') ?: self::DEFAULT_EXTERNAL_MODEL,
            'has_key' => (string) ($raw['api_key'] ?? '') !== '',
            'allow_personal_data' => (bool) ($raw['allow_personal_data'] ?? false),
            'disabled_use_cases' => array_values(array_map('strval', (array) ($raw['disabled_use_cases'] ?? []))),
        ];
    }

    /**
     * @param list<string> $disabledUseCases
     * @return array<string, mixed> the stored values without the key, for the audit log
     */
    public function saveTenant(
        Tenant $tenant,
        string $mode,
        string $endpoint,
        string $model,
        #[\SensitiveParameter] ?string $apiKey,
        bool $clearKey,
        bool $allowPersonalData,
        array $disabledUseCases,
    ): array {
        $settings = $tenant->getSettings() ?? [];
        $old = (array) ($settings[self::TENANT_KEY] ?? []);
        $new = [
            'mode' => in_array($mode, self::MODES, true) ? $mode : self::MODE_OFF,
            'endpoint' => $endpoint,
            'model' => $model,
            'api_key' => $clearKey ? '' : (($apiKey !== null && $apiKey !== '') ? (string) $this->encryption->encrypt($apiKey) : (string) ($old['api_key'] ?? '')),
            'allow_personal_data' => $allowPersonalData,
            'disabled_use_cases' => array_values(array_unique($disabledUseCases)),
        ];
        $settings[self::TENANT_KEY] = $new;
        $tenant->setSettings($settings);
        $this->entityManager->flush();
        unset($new['api_key']);

        return $new + ['key_changed' => $clearKey || ($apiKey !== null && $apiKey !== '')];
    }

    public function isUseCaseEnabled(Tenant $tenant, string $useCase): bool
    {
        $cfg = $this->tenant($tenant);

        return $cfg['mode'] !== self::MODE_OFF && !in_array($useCase, $cfg['disabled_use_cases'], true);
    }

    /** The endpoint this organisation uses, or null when AI is off or the operator set up no local model. */
    public function endpointFor(Tenant $tenant): ?AiEndpoint
    {
        $cfg = $this->tenant($tenant);
        if ($cfg['mode'] === self::MODE_LOCAL) {
            $instance = $this->instance();
            if (!$instance['configured']) {
                return null;
            }
            $key = (string) $this->systemSettings->getSetting(self::CATEGORY, 'local_api_key', '');

            return new AiEndpoint($instance['endpoint'], $instance['model'], $key !== '' ? $key : null, false, false, 120);
        }
        if ($cfg['mode'] === self::MODE_EXTERNAL) {
            $raw = (array) (($tenant->getSettings() ?? [])[self::TENANT_KEY] ?? []);
            $key = $this->encryption->decrypt((string) ($raw['api_key'] ?? '') ?: null);

            return new AiEndpoint($cfg['endpoint'], $cfg['model'], $key, true, !$cfg['allow_personal_data'], 60);
        }

        return null;
    }
}
