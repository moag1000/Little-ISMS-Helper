<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Tenant;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\DataRepair\LegacyOwnerReport;
use App\Service\DataRepair\LegacyOwnerRow;
use App\Service\TenantContext;
use App\Util\ListPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Data repair: records whose owner is only a legacy free text (Form Pattern A).
 *
 * Scope is the admin's organisation plus its subsidiaries
 * ({@see TenantContext::getAccessibleTenants()}), the same tree
 * `ADMIN_OWN_TENANT` grants. Every POST re-reads the row inside that scope and
 * recomputes the suggestion server-side, so a crafted id can neither reach
 * another organisation nor assign an account the page did not offer.
 */
#[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
final class LegacyOwnerController extends AbstractController
{
    public function __construct(
        private readonly LegacyOwnerReport $report,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/data-repair/legacy-owners', name: 'admin_legacy_owner_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenants = $this->scope();
        $types = $this->report->activeTypes();
        $type = (string) $request->query->get('type', '');
        $type = in_array($type, $types, true) ? $type : null;
        $filter = (string) $request->query->get('match', '');

        $rows = $this->report->openRows($tenants, $type);
        $withSuggestion = count(array_filter($rows, static fn (LegacyOwnerRow $r): bool => $r->suggestion !== null));
        if ($filter === 'suggested') {
            $rows = array_values(array_filter($rows, static fn (LegacyOwnerRow $r): bool => $r->suggestion !== null));
        } elseif ($filter === 'none') {
            $rows = array_values(array_filter($rows, static fn (LegacyOwnerRow $r): bool => $r->suggestion === null));
        }

        return $this->render('admin/data_repair/legacy_owners.html.twig', [
            'page' => ListPage::fromRequest(
                $rows,
                $request,
                static fn (LegacyOwnerRow $r): array => [$r->title, $r->legacy, $r->suggestionName(), $r->tenant?->getName()],
            ),
            'types' => $types,
            'filterType' => $type,
            'filterMatch' => $filter,
            'withSuggestion' => $withSuggestion,
            'multiTenant' => count($tenants) > 1,
        ]);
    }

    #[Route('/admin/data-repair/legacy-owners/{type}/{id}/apply', name: 'admin_legacy_owner_apply', requirements: ['type' => '[a-z_]+', 'id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('legacy_owner')]
    public function apply(string $type, int $id, Request $request): Response
    {
        $row = $this->report->findOpenRow($type, $id, $this->scope());
        if ($row !== null && $this->report->applySuggestion($row)) {
            $this->addFlash('success', $this->translator->trans('admin.legacy_owners.flash.applied', [], 'admin'));
        } else {
            $this->addFlash('warning', $this->translator->trans('admin.legacy_owners.flash.not_open', [], 'admin'));
        }

        return $this->back($request);
    }

    // @no-module-gate-required: Person records are the target of the owner picker in every form, with or without the persons module
    #[Route('/admin/data-repair/legacy-owners/{type}/{id}/person', name: 'admin_legacy_owner_person', requirements: ['type' => '[a-z_]+', 'id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('legacy_owner')]
    public function recordAsPerson(string $type, int $id, Request $request): Response
    {
        $row = $this->report->findOpenRow($type, $id, $this->scope());
        if ($row === null) {
            $this->addFlash('warning', $this->translator->trans('admin.legacy_owners.flash.not_open', [], 'admin'));

            return $this->back($request);
        }
        $person = $this->report->resolveAsPerson($row);
        $this->addFlash('success', $this->translator->trans('admin.legacy_owners.flash.person', ['%name%' => $person->getFullName()], 'admin'));

        return $this->back($request);
    }

    #[Route('/admin/data-repair/legacy-owners/apply-all', name: 'admin_legacy_owner_apply_all', methods: ['POST'])]
    #[IsCsrfTokenValid('legacy_owner')]
    public function applyAll(Request $request): Response
    {
        $resolved = $this->report->applyAllSuggestions($this->report->openRows($this->scope()));
        $this->addFlash(
            $resolved > 0 ? 'success' : 'warning',
            $this->translator->trans('admin.legacy_owners.flash.applied_all', ['%count%' => $resolved], 'admin'),
        );

        return $this->back($request);
    }

    /**
     * @return list<Tenant>
     */
    private function scope(): array
    {
        return array_values($this->tenantContext->getAccessibleTenants());
    }

    /**
     * Back to the list with its filters; only the list's own query string is kept.
     */
    private function back(Request $request): Response
    {
        $query = array_filter([
            'type' => $request->request->get('type'),
            'match' => $request->request->get('match'),
            'q' => $request->request->get('q'),
        ], static fn ($v): bool => is_string($v) && $v !== '');

        return $this->redirectToRoute('admin_legacy_owner_index', $query);
    }
}
