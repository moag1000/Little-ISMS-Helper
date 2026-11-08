<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\ISMSContext;
use App\Entity\Tenant;
use App\Repository\ISMSContextRepository;
use App\Service\ISMSContextService;
use App\Service\Register\IsmsScopeSummary;
use App\Service\TenantContext;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * ISO 27001 4.3 on the pages and reports that describe the ISMS (SoA, management
 * reports): the scope statement of the context plus what the inventories count
 * inside / outside the scope. `{% set scope = isms_scope() %}` with the partial
 * `_components/_isms_scope_block.html.twig`, or `isms_scope_section()` for `_fa_report_doc`.
 */
final class IsmsScopeExtension
{
    /** @var array<int, array<string, mixed>> */
    private array $memo = [];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ISMSContextRepository $contexts,
        private readonly ISMSContextService $contextService,
        private readonly IsmsScopeSummary $summary,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The scope as a section of `_fa_report_doc` (management reports):
     * `{% set _sections = isms_scope_section()|merge(_sections) %}`. Empty list
     * without an organisation.
     *
     * @return list<array{heading: string, intro: string, table: array{headers: list<string>, rows: list<list<int|string>>}}>
     */
    #[AsTwigFunction('isms_scope_section')]
    public function section(?Tenant $tenant = null): array
    {
        $scope = $this->scope($tenant);
        if ($scope === null) {
            return [];
        }
        $t = fn (string $key): string => $this->translator->trans($key, [], 'register');
        $rows = [];
        foreach ($scope['counts'] as $key => $count) {
            $rows[] = [$t('register.scope.' . $key), $count['in'], $count['out']];
        }
        $intro = trim((string) $scope['statement']) !== '' ? (string) $scope['statement'] : $t('register.scope.no_statement');
        if (trim((string) $scope['exclusions']) !== '') {
            $intro .= ' ' . $t('register.scope.exclusions') . ': ' . $scope['exclusions'];
        }

        return [[
            'heading' => $t('register.scope.title'),
            'intro' => $intro,
            'table' => [
                'headers' => [$t('register.scope.col_inventory'), $t('register.scope.col_in'), $t('register.scope.col_out')],
                'rows' => $rows,
            ],
        ]];
    }

    /**
     * @return array{statement: ?string, exclusions: ?string, counts: array<string, array{in: int, out: int, route: string}>}|null
     */
    #[AsTwigFunction('isms_scope')]
    public function scope(?Tenant $tenant = null): ?array
    {
        $tenant ??= $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return null;
        }

        return $this->memo[(int) $tenant->getId()] ??= $this->build($tenant);
    }

    /** @return array{statement: ?string, exclusions: ?string, counts: array<string, array{in: int, out: int, route: string}>} */
    private function build(Tenant $tenant): array
    {
        $context = $this->contexts->getContextForTenant($tenant);
        $effective = $context instanceof ISMSContext ? $this->contextService->getEffectiveContext($context) : null;

        return [
            'statement' => $effective?->getIsmsScope(),
            'exclusions' => $effective?->getScopeExclusions(),
            'counts' => $this->summary->summarize($tenant),
        ];
    }
}
