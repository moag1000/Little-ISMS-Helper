<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SoaSnapshot;
use App\Entity\User;
use App\Repository\SoaSnapshotRepository;
use App\Service\Soa\SoaSnapshotService;
use App\Service\TenantContext;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * SoA point-in-time snapshot controller.
 *
 * Closes the persona-walkthrough gap (ISB + Auditor-External) by
 * surfacing the Statement-of-Applicability freeze workflow:
 *   - List existing snapshots (chronological)
 *   - Create a new snapshot for an arbitrary `as-of-date`
 *   - Inspect a snapshot detail (control count, sha256, payload)
 *   - Download the frozen state as CSV
 *
 * RBAC: ROLE_AUDITOR is the floor (auditors must be able to freeze
 * the SoA before an external audit + inspect prior freezes). Higher
 * roles inherit (MANAGER, ADMIN, GROUP_CISO).
 */
#[IsGranted('ROLE_AUDITOR')]
class SoaSnapshotController extends AbstractController
{
    public function __construct(
        private readonly SoaSnapshotService $snapshotService,
        private readonly SoaSnapshotRepository $snapshotRepository,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/soa/snapshot', name: 'app_soa_snapshot_index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $snapshots = $tenant !== null
            ? $this->snapshotRepository->findByTenant($tenant)
            : [];

        return $this->render('soa_snapshot/index.html.twig', [
            'snapshots' => $snapshots,
            'tenant'    => $tenant,
        ]);
    }

    #[Route('/soa/snapshot/new', name: 'app_soa_snapshot_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $user   = $this->security->getUser();

        if ($tenant === null || !$user instanceof User) {
            throw $this->createAccessDeniedException('Tenant context or user missing.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('soa_snapshot_create', (string) $request->request->get('_token'))) {
                $this->addFlash('error', $this->translator->trans('common.csrf_invalid', [], 'messages'));
                return $this->redirectToRoute('app_soa_snapshot_new');
            }

            $rawDate = trim((string) $request->request->get('as_of_date', ''));
            $purpose = trim((string) $request->request->get('purpose', ''));
            $notes   = trim((string) $request->request->get('notes', ''));

            $asOfDate = $this->parseDate($rawDate);
            if ($asOfDate === null) {
                $this->addFlash('error', $this->translator->trans('soa.snapshot.error.invalid_date', [], 'soa'));
                return $this->redirectToRoute('app_soa_snapshot_new');
            }
            if ($asOfDate > new DateTimeImmutable()) {
                $this->addFlash('error', $this->translator->trans('soa.snapshot.error.future_date', [], 'soa'));
                return $this->redirectToRoute('app_soa_snapshot_new');
            }

            $snapshot = $this->snapshotService->createSnapshot(
                $tenant,
                $asOfDate,
                $user,
                $purpose !== '' ? $purpose : null,
                $notes !== '' ? $notes : null,
            );

            $this->addFlash('success', $this->translator->trans(
                'soa.snapshot.flash.created',
                ['%date%' => $asOfDate->format('Y-m-d'), '%count%' => $snapshot->getControlCount()],
                'soa',
            ));

            return $this->redirectToRoute('app_soa_snapshot_show', ['id' => $snapshot->getId()]);
        }

        return $this->render('soa_snapshot/new.html.twig', [
            'tenant' => $tenant,
            'today'  => (new DateTimeImmutable())->format('Y-m-d'),
        ]);
    }

    #[Route('/soa/snapshot/{id}', name: 'app_soa_snapshot_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(SoaSnapshot $snapshot): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($snapshot->getTenant()?->getId() !== $tenant?->getId()) {
            throw $this->createAccessDeniedException('Snapshot belongs to a different tenant.');
        }

        return $this->render('soa_snapshot/show.html.twig', [
            'snapshot' => $snapshot,
            'controls' => $snapshot->getPayload()['controls'] ?? [],
        ]);
    }

    #[Route('/soa/snapshot/{id}/export-csv', name: 'app_soa_snapshot_export_csv', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportCsv(SoaSnapshot $snapshot): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($snapshot->getTenant()?->getId() !== $tenant?->getId()) {
            throw $this->createAccessDeniedException('Snapshot belongs to a different tenant.');
        }

        $csv = $this->snapshotService->exportPayloadCsv($snapshot);
        $filename = sprintf(
            'soa_snapshot_%s_%s.csv',
            $snapshot->getAsOfDate()->format('Ymd'),
            $snapshot->getId() ?? 0,
        );

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        return $response;
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
            if ($date === false) {
                return null;
            }
            return $date->setTime(23, 59, 59);
        } catch (\Throwable) {
            return null;
        }
    }
}
