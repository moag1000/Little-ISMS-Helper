<?php

declare(strict_types=1);

namespace App\Controller\Authority;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\AuthorityTemplate;
use App\Repository\DataBreachRepository;
use App\Repository\IncidentRepository;
use App\Service\Authority\AuthorityNotificationGenerator;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * F26.3 — Authority Notification Export Controller
 *
 * Pre-fills BSI-Meldestelle, BfDI, and 16 LfDI submission forms from
 * existing DataBreach + Incident entities.
 *
 * Access: ROLE_DPO (inherits ROLE_MANAGER)
 * Module: eu_authority_reporting
 */
#[Route('/authority/notification', name: 'app_authority_notification_')]
#[IsGranted('ROLE_MANAGER')]
final class AuthorityNotificationController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly AuthorityNotificationGenerator $generator,
        private readonly TenantContext $tenantContext,
        private readonly DataBreachRepository $dataBreachRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Index — overview of available authority export actions.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('eu_authority_reporting')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context.');
        }

        $breaches = $this->dataBreachRepository->findByTenant($tenant);
        $incidents = $this->incidentRepository->findBy(['tenant' => $tenant], ['detectedAt' => 'DESC'], 50);
        $authorityKeys = AuthorityTemplate::VALID_AUTHORITY_KEYS;

        return $this->render('authority/notification/index.html.twig', [
            'breaches' => $breaches,
            'incidents' => $incidents,
            'authority_keys' => $authorityKeys,
        ]);
    }

    /**
     * Export a DataBreach notification as PDF for the given authority.
     */
    #[Route('/data-breach/{breachId}/{authorityKey}.pdf', name: 'breach_pdf', methods: ['GET'], requirements: ['breachId' => '\d+'])]
    public function breachPdf(int $breachId, string $authorityKey): Response
    {
        if ($redirect = $this->checkModuleActive('eu_authority_reporting')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context.');
        }

        $breach = $this->dataBreachRepository->find($breachId);
        if ($breach === null || $breach->getTenant() !== $tenant) {
            throw $this->createNotFoundException('DataBreach not found.');
        }

        if (!in_array($authorityKey, AuthorityTemplate::VALID_AUTHORITY_KEYS, true)) {
            throw $this->createNotFoundException(sprintf('Unknown authority key: %s', $authorityKey));
        }

        $pdf = match (true) {
            $authorityKey === AuthorityTemplate::AUTHORITY_BFDI => $this->generator->generateBfdiBreachPdf($breach, $tenant),
            str_starts_with($authorityKey, 'lfdi_') => $this->generator->generateLfdiBreachPdf($breach, $tenant, $authorityKey),
            default => throw $this->createNotFoundException(sprintf('Authority key "%s" not supported for DataBreach PDF.', $authorityKey)),
        };

        $filename = sprintf(
            '%s-DataBreach-%d-%s.pdf',
            strtoupper($authorityKey),
            $breachId,
            (new \DateTimeImmutable())->format('Ymd'),
        );

        return new Response(
            $pdf,
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            ],
        );
    }

    /**
     * Export an Incident notification as PDF for BSI-Meldestelle.
     */
    #[Route('/incident/{incidentId}/{authorityKey}.pdf', name: 'incident_pdf', methods: ['GET'], requirements: ['incidentId' => '\d+'])]
    public function incidentPdf(int $incidentId, string $authorityKey): Response
    {
        if ($redirect = $this->checkModuleActive('eu_authority_reporting')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context.');
        }

        $incident = $this->incidentRepository->find($incidentId);
        if ($incident === null || $incident->getTenant() !== $tenant) {
            throw $this->createNotFoundException('Incident not found.');
        }

        if ($authorityKey !== AuthorityTemplate::AUTHORITY_BSI_MELDESTELLE) {
            throw $this->createNotFoundException(sprintf('Authority key "%s" is not supported for Incident PDF. Use "bsi_meldestelle".', $authorityKey));
        }

        $pdf = $this->generator->generateBsiMeldestellePdf($incident, $tenant);

        $filename = sprintf(
            'BSI-Meldestelle-Incident-%d-%s.pdf',
            $incidentId,
            (new \DateTimeImmutable())->format('Ymd'),
        );

        return new Response(
            $pdf,
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            ],
        );
    }

    /**
     * Export a DataBreach notification as JSON (machine-readable).
     */
    #[Route('/data-breach/{breachId}/{authorityKey}.json', name: 'breach_json', methods: ['GET'], requirements: ['breachId' => '\d+'])]
    public function breachJson(int $breachId, string $authorityKey): JsonResponse
    {
        if ($redirect = $this->checkModuleActive('eu_authority_reporting')) {
            return new JsonResponse(['error' => 'Module not active'], Response::HTTP_FORBIDDEN);
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context.');
        }

        $breach = $this->dataBreachRepository->find($breachId);
        if ($breach === null || $breach->getTenant() !== $tenant) {
            throw $this->createNotFoundException('DataBreach not found.');
        }

        if (!in_array($authorityKey, AuthorityTemplate::VALID_AUTHORITY_KEYS, true)) {
            throw $this->createNotFoundException(sprintf('Unknown authority key: %s', $authorityKey));
        }

        $payload = $this->generator->generateJson($breach, $tenant, $authorityKey);

        // Convert non-serialisable objects to strings for JSON output
        array_walk_recursive($payload, static function (mixed &$value): void {
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format(\DateTimeInterface::ATOM);
            } elseif (is_object($value)) {
                $value = (string) ($value->getId() ?? class_basename($value));
            }
        });

        return new JsonResponse($payload);
    }
}
