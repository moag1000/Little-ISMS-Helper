<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\IncidentSlaConfig;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\Admin\IncidentSlaConfigType;
use App\Repository\IncidentSlaConfigRepository;
use App\Security\Voter\TenantScopedAdminVoter;
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
 *
 * Phase 4c role-scope migration: ROLE_ADMIN configures own tenant,
 * SUPER_ADMIN any. Tenant scope resolves via
 * {@see TenantContext::resolveAdminScope()} (optional `tenant_id`
 * POST param for SUPER_ADMIN cross-tenant).
 */
#[Route('/admin/incident-sla')]
#[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
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
    public function index(Request $request): Response
    {
        $tenant = $this->requireTenant($request);

        // Defaults ggf. anlegen (Idempotent) — falls Migration für neuen Tenant noch nicht lief.
        // Repository persists ohne flush → wir flushen hier selbst.
        $this->repository->ensureDefaultsFor($tenant);
        $this->entityManager->flush();

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
        $tenant = $this->requireTenant($request);
        // Resolve scope of the loaded config and let SUPER_ADMIN /
        // tenant-admin authority decide if it's accessible.
        $configTenant = $this->tenantContext->resolveAdminScope($config->getTenant()?->getId());
        if ($configTenant?->getId() !== $config->getTenant()?->getId()) {
            throw $this->createAccessDeniedException('Config belongs to different tenant.');
        }
        // For the resolver and audit log we keep the tenant of the
        // config (SUPER_ADMIN may legitimately edit another tenant's row).
        $tenant = $configTenant;

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

    private function requireTenant(Request $request): Tenant
    {
        $tenant = $this->tenantContext->resolveAdminScope($request->request->get('tenant_id'));
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('No tenant context.');
        }
        return $tenant;
    }
}
