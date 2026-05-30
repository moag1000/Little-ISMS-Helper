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
        private PolicyParameterCatalog $catalog,
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
                $this->localizedParamLabel($row->paramKey, $row->label),
                $this->formatValue($row->paramKey, $row->value),
                $authority,
                $row->source ?? $dash,
                $frameworksCol,
            );
        }

        return rtrim($md);
    }

    /**
     * Localizes the parameter name to the active translator locale via the
     * catalog's bilingual labels. Falls back to the register row's (German)
     * label, then the param key.
     */
    private function localizedParamLabel(string $paramKey, string $fallback): string
    {
        $locale = substr($this->translator->getLocale(), 0, 2);
        $labels = $this->catalog->get($paramKey)->labels;

        return $labels[$locale] ?? $fallback;
    }

    /**
     * Renders a human-readable label for an enum value (e.g. `all` →
     * "alle Konten") via `annex.value_label.<paramKey>.<value>`. Falls back to
     * the raw value when no label is registered (ints, unmapped enums) so the
     * canonical parameter value still shows.
     */
    private function formatValue(string $paramKey, mixed $value): string
    {
        if (is_bool($value)) {
            return $this->translator->trans(
                $value ? 'annex.value.bool_true' : 'annex.value.bool_false',
                [],
                'policy_wizard',
            );
        }

        $raw = (string) $value;
        $key = 'annex.value_label.' . $paramKey . '.' . $raw;
        $label = $this->translator->trans($key, [], 'policy_wizard');

        return $label === $key ? $raw : $label;
    }
}
