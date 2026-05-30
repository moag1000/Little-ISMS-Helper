<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

use App\Repository\OrganizationSecurityProfileRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Renders the "Binding Security Parameters" annex appended to every generated
 * policy document. It surfaces the tenant's effective policy-parameter values
 * (override → profile → baseline → default) together with the strongest
 * applicable framework authority + source — the same data as the cross-framework
 * parameter register, formatted as a Markdown section so the wizard's
 * DocumentGenerator can append it to the policy body.
 *
 * Returns an empty string when the tenant has no OrganizationSecurityProfile,
 * so policies for tenants that never configured parameters stay unchanged.
 */
final readonly class PolicyParameterAnnexRenderer
{
    public function __construct(
        private OrganizationSecurityProfileRepository $profiles,
        private PolicyProfileManager $profileManager,
        private ParameterRegisterBuilder $registerBuilder,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<string> $frameworks the standards adopted in the wizard run
     */
    public function renderForTenant(int $tenantId, array $frameworks): string
    {
        $profile = $this->profiles->findForTenant($tenantId);
        if ($profile === null) {
            return '';
        }

        $resolved = $this->profileManager->resolveAll($profile);
        $rows = $this->registerBuilder->build($frameworks, $resolved);
        if ($rows === []) {
            return '';
        }

        $t = fn (string $key): string => $this->translator->trans($key, [], 'policy_wizard');
        $dash = '–';

        $md = '## ' . $t('annex.heading') . "\n\n";
        $md .= $t('annex.intro') . "\n\n";
        $md .= '| ' . $t('annex.col.parameter')
            . ' | ' . $t('annex.col.value')
            . ' | ' . $t('annex.col.authority')
            . ' | ' . $t('annex.col.source')
            . ' | ' . $t('annex.col.frameworks') . " |\n";
        $md .= "|---|---|---|---|---|\n";

        foreach ($rows as $row) {
            $authority = $row->authority !== null ? $t('annex.authority.' . $row->authority) : $dash;
            $frameworksCol = $row->frameworks !== []
                ? strtoupper(implode(', ', $row->frameworks))
                : $dash;

            $md .= sprintf(
                "| %s | %s | %s | %s | %s |\n",
                $row->label,
                $this->formatValue($row->value),
                $authority,
                $row->source ?? $dash,
                $frameworksCol,
            );
        }

        return rtrim($md);
    }

    private function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $this->translator->trans(
                $value ? 'annex.value.bool_true' : 'annex.value.bool_false',
                [],
                'policy_wizard',
            );
        }

        return (string) $value;
    }
}
