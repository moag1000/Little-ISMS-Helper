<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard;

/**
 * Registry that collects every {@see PolicyWizardCheckInterface} implementation
 * via the `policy_wizard.compliance_check` tag (see `config/services.yaml`).
 *
 * Lets the Compliance-Wizard iterate all Policy-Wizard checks for a given
 * standard without growing the legacy switch-statement inside
 * `ComplianceWizardService::runCheck()`. Order is the autowiring-tag order
 * (definition order) — callers that need deterministic ordering should sort
 * by `getCheckId()`.
 */
final class PolicyWizardCheckRegistry
{
    /**
     * @var iterable<PolicyWizardCheckInterface>
     */
    private iterable $checks;

    /**
     * @param iterable<PolicyWizardCheckInterface> $checks
     */
    public function __construct(iterable $checks)
    {
        $this->checks = $checks;
    }

    /**
     * @return list<PolicyWizardCheckInterface>
     */
    public function all(): array
    {
        return is_array($this->checks) ? array_values($this->checks) : iterator_to_array($this->checks, false);
    }

    /**
     * @return list<PolicyWizardCheckInterface>
     */
    public function forStandard(string $standard): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(PolicyWizardCheckInterface $c): bool => $c->getStandard() === $standard,
        ));
    }

    public function get(string $checkId): ?PolicyWizardCheckInterface
    {
        foreach ($this->all() as $check) {
            if ($check->getCheckId() === $checkId) {
                return $check;
            }
        }
        return null;
    }
}
