<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\IncidentSlaConfig;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\Admin\IncidentSlaConfigType;
use App\Repository\IncidentSlaConfigRepository;
use App\Service\AuditLogger;
use App\Service\IncidentSlaConfigResolver;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Phase 8L.F2 — Admin-UI für Incident-SLAs pro Severity.
 */
#[Route('/admin/incident-sla')]
#[IsGranted('ROLE_ADMIN')]
class IncidentSlaConfigController extends AbstractController
{
    public function __construct(
        private readonly IncidentSlaConfigRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly IncidentSlaConfigResolver $resolver,
    ) {
    }

    #[Route('', name: 'app_admin_incident_sla_config', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->requireTenant();

        // Defaults ggf. anlegen (Idempotent) — falls Migration für neuen Tenant noch nicht lief.
        $this->repository->ensureDefaultsFor($tenant);

        $configs = $this->repository->findByTenant($tenant);
        // Nach Severity-Reihenfolge sortieren: breach, critical, high, medium, low
        $order = array_flip([
            IncidentSlaConfig::SEVERITY_BREACH,
            IncidentSlaConfig::SEVERITY_CRITICAL,
            IncidentSlaConfig::SEVERITY_HIGH,
            IncidentSlaConfig::SEVERITY_MEDIUM,
            IncidentSlaConfig::SEVERITY_LOW,
        ]);
        usort($configs, fn ($a, $b) => ($order[$a->getSeverity()] ?? 99) <=> ($order[$b->getSeverity()] ?? 99));

        return $this->render('admin/incident_sla_config/index.html.twig', [
            'configs' => $configs,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_incident_sla_config_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, IncidentSlaConfig $config): Response
    {
        $tenant = $this->requireTenant();
        if ($config->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('Config belongs to different tenant.');
        }

        $oldValues = [
            'response_hours' => $config->getResponseHours(),
            'escalation_hours' => $config->getEscalationHours(),
            'resolution_hours' => $config->getResolutionHours(),
        ];

        $form = $this->createForm(IncidentSlaConfigType::class, $config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUser();
            if ($user instanceof User) {
                $config->setUpdatedBy($user);
            }
            $config->setUpdatedAt(new DateTimeImmutable());

            $this->entityManager->flush();
            $this->resolver->invalidate($tenant);

            $newValues = [
                'response_hours' => $config->getResponseHours(),
                'escalation_hours' => $config->getEscalationHours(),
                'resolution_hours' => $config->getResolutionHours(),
            ];

            $this->auditLogger->logUpdate(
                'IncidentSlaConfig',
                $config->getId(),
                $oldValues,
                $newValues,
                sprintf('SLA für Severity "%s" aktualisiert.', $config->getSeverity()),
            );

            $this->addFlash('success', 'incident_sla.saved');
            return $this->redirectToRoute('app_admin_incident_sla_config');
        }

        return $this->render('admin/incident_sla_config/edit.html.twig', [
            'form' => $form,
            'config' => $config,
        ]);
    }

    private function requireTenant(): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('No tenant context.');
        }
        return $tenant;
    }
}
