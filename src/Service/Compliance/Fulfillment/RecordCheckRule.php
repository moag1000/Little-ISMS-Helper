<?php

declare(strict_types=1);

namespace App\Service\Compliance\Fulfillment;

use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Service\Catalog\FrameworkCode;
use App\Service\ComplianceWizard\WizardCheckSource;
use App\Service\ComplianceWizardService;
use App\Service\ModuleConfigurationService;
use Closure;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Fulfilment of a requirement from the organisation's records, through the
 * compliance wizard's checks: every wizard check names the clause or article
 * it covers (`clause` for ISO, `article` for EU regulation), so ISO 27001 7.2
 * follows the training records, 9.3 the management reviews, DORA Art. 28 the
 * supplier register — one mechanism for every norm, no per-norm code.
 *
 * A check covers a requirement when its reference equals the requirement's
 * number ("7.2" ↔ "ISO27001-7.2", "21(2)(a)" ↔ "NIS2-21.2.a") or, for a
 * reference with two or more levels, is its parent ("9.2" covers 9.2.1 and
 * 9.2.2). Several checks average. Manual checks, checks of inactive modules
 * and checks over zero records ("no data", no control, process, … at all) do not
 * count: an empty module proves nothing either way, so the requirement stays
 * with its other rules or the hand value.
 */
class RecordCheckRule implements ResetInterface
{
    /** @var array<int, array<string, list<array{key: string, check: array<string, mixed>}>>> tenant id → framework → checks */
    private array $index = [];

    /** @var array<string, array<string, mixed>|false> "tenant:wizard:check" → result (false = no usable result) */
    private array $results = [];

    /**
     * @param Closure(): WizardCheckSource $wizards lazy: the wizard reads half the repositories
     * @param Closure(): ModuleConfigurationService $modules lazy: the rule engine is built early (Doctrine
     *        listeners), the module configuration only when a requirement is derived
     */
    public function __construct(
        #[AutowireServiceClosure(ComplianceWizardService::class)]
        private readonly Closure $wizards,
        #[AutowireServiceClosure(ModuleConfigurationService::class)]
        private readonly Closure $modules,
    ) {
    }

    public function derive(ComplianceRequirement $requirement, Tenant $tenant): ?FulfillmentDerivation
    {
        $code = (string) $requirement->getFramework()?->getCode();
        $number = self::requirementNumber($code, (string) $requirement->getRequirementId());
        if ($number === '') {
            return null;
        }

        $basis = [];
        $sum = 0.0;
        foreach ($this->checksFor($tenant, $code) as $ref => $checks) {
            if (!self::covers($ref, $number)) {
                continue;
            }
            foreach ($checks as ['key' => $key, 'check' => $check]) {
                $result = $this->result($tenant, $code, $key, $check);
                if ($result === null) {
                    continue;
                }
                $value = (int) round(max(0.0, min(100.0, (float) $result['score'])));
                $basis[] = [
                    'type' => 'record',
                    'ref' => $ref,
                    'label' => (string) ($check['name'] ?? $key),
                    'domain' => (string) ($check['translation_domain'] ?? 'wizard'),
                    'route' => is_string($check['route'] ?? null) ? $check['route'] : null,
                    'value' => $value,
                ];
                $sum += $value;
            }
        }

        if ($basis === []) {
            return null;
        }

        return new FulfillmentDerivation(
            (int) round($sum / count($basis)),
            FulfillmentRuleEngine::KIND_RECORDS,
            $basis,
        );
    }

    /**
     * "21(2)(a)" → "21.2.a", "6.1.3 d)" → "6.1.3.d", " 7.2 " → "7.2".
     */
    public static function normaliseReference(string $reference): string
    {
        $ref = strtolower(trim($reference));
        $ref = (string) preg_replace('/[()\s]+/', '.', $ref);

        return trim((string) preg_replace('/\.{2,}/', '.', $ref), '.');
    }

    /** "ISO27001-7.2" → "7.2", "NIS2-21.2.a" → "21.2.a", "DORA-6.1" → "6.1". */
    public static function requirementNumber(string $frameworkCode, string $requirementId): string
    {
        $id = trim($requirementId);
        if ($frameworkCode !== '' && stripos($id, $frameworkCode . '-') === 0) {
            $id = substr($id, strlen($frameworkCode) + 1);
        }

        return self::normaliseReference($id);
    }

    public static function covers(string $reference, string $requirementNumber): bool
    {
        if ($reference === $requirementNumber) {
            return true;
        }

        return substr_count($reference, '.') >= 1 && str_starts_with($requirementNumber, $reference . '.');
    }

    /**
     * No record behind the check: the explicit "no data" result, an entity count
     * of zero, or a check over zero records (no control, process, asset … at all).
     *
     * @param array<string, mixed> $details
     */
    public static function isEmpty(array $details): bool
    {
        if (($details['reason'] ?? null) === 'no_data') {
            return true;
        }
        if (($details['type'] ?? null) === 'entity_presence') {
            return (int) ($details['count'] ?? 0) === 0;
        }
        foreach (['total', 'total_audits'] as $key) {
            if (array_key_exists($key, $details)) {
                return (int) $details[$key] === 0 && (int) ($details['excluded'] ?? 0) === 0;
            }
        }

        return false;
    }

    public function reset(): void
    {
        $this->index = [];
        $this->results = [];
    }

    /** Fresh check results after the organisation's records changed in this request. */
    public function forgetTenant(Tenant $tenant): void
    {
        $prefix = $tenant->getId() . ':';
        foreach (array_keys($this->results) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->results[$key]);
            }
        }
    }

    /**
     * Data checks of the framework's wizard, by normalised reference.
     *
     * @return array<string, list<array{key: string, check: array<string, mixed>}>>
     */
    private function checksFor(Tenant $tenant, string $frameworkCode): array
    {
        $tenantId = (int) $tenant->getId();
        if (!isset($this->index[$tenantId])) {
            $this->index[$tenantId] = $this->buildIndex($tenant);
        }

        return $this->index[$tenantId][FrameworkCode::canonicalize($frameworkCode) ?? $frameworkCode] ?? [];
    }

    /**
     * @return array<string, array<string, list<array{key: string, check: array<string, mixed>}>>>
     */
    private function buildIndex(Tenant $tenant): array
    {
        $active = ($this->modules)()->getActiveModulesForTenant($tenant);
        $index = [];
        foreach (($this->wizards)()->getWizardDefinitions() as $wizardKey => $wizard) {
            $code = (string) ($wizard['code'] ?? '');
            $code = FrameworkCode::canonicalize($code) ?? $code;
            foreach ((array) ($wizard['categories'] ?? []) as $categoryKey => $category) {
                foreach ((array) ($category['checks'] ?? []) as $checkKey => $check) {
                    if (!is_array($check) || ($check['type'] ?? 'manual') === 'manual') {
                        continue;
                    }
                    if (isset($check['module']) && !in_array($check['module'], $active, true)) {
                        continue;
                    }
                    $raw = (string) ($check['clause'] ?? $check['article'] ?? '');
                    foreach (explode('/', $raw) as $part) {
                        $ref = self::normaliseReference($part);
                        // Ranges ("24-25") and Annex-A themes ("A.5") are not one requirement.
                        if ($ref === '' || str_contains($ref, '-') || str_starts_with($ref, 'a.')) {
                            continue;
                        }
                        $index[$code][$ref][] = [
                            'key' => $wizardKey . '.' . $categoryKey . '.' . $checkKey,
                            'check' => $check,
                        ];
                    }
                }
            }
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $check
     * @return array<string, mixed>|null
     */
    private function result(Tenant $tenant, string $frameworkCode, string $key, array $check): ?array
    {
        $cacheKey = $tenant->getId() . ':' . $frameworkCode . ':' . $key;
        if (!array_key_exists($cacheKey, $this->results)) {
            $result = ($this->wizards)()->runDataCheck($check, $tenant);
            $this->results[$cacheKey] = $result === null || self::isEmpty((array) ($result['details'] ?? [])) ? false : $result;
        }

        return $this->results[$cacheKey] === false ? null : $this->results[$cacheKey];
    }
}
