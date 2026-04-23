<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\RiskApprovalConfig;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\Admin\RiskApprovalConfigType;
use App\Repository\RiskApprovalConfigRepository;
use App\Service\AuditLogger;
use App\Service\RiskApprovalConfigResolver;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Phase 8L.F1 — Admin-UI für Approval-Schwellwerte pro Tenant.
 */
#[Route('/admin/risk-governance/approval-thresholds')]
#[IsGranted('ROLE_ADMIN')]
class RiskApprovalConfigController extends AbstractController
{
    public function __construct(
        private readonly RiskApprovalConfigRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly RiskApprovalConfigResolver $resolver,
    ) {
    }

    #[Route('', name: 'app_admin_risk_approval_config', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('No tenant context.');
        }

        $config = $this->repository->findByTenant($tenant);
        $isNew = !$config instanceof RiskApprovalConfig;
        if ($isNew) {
            $config = new RiskApprovalConfig();
            $config->setTenant($tenant);
            // Defaults aus View (synchron mit Service-Fallback)
            $config->setThresholdAutomatic(3);
            $config->setThresholdManager(7);
            $config->setThresholdExecutive(25);
        }

        $oldValues = [
            'threshold_automatic' => $config->getThresholdAutomatic(),
            'threshold_manager' => $config->getThresholdManager(),
            'threshold_executive' => $config->getThresholdExecutive(),
        ];

        $form = $this->createForm(RiskApprovalConfigType::class, $config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUser();
            if ($user instanceof User) {
                $config->setUpdatedBy($user);
            }
            $config->setUpdatedAt(new DateTimeImmutable());

            $this->entityManager->persist($config);
            $this->entityManager->flush();
            $this->resolver->invalidate($tenant);

            $newValues = [
                'threshold_automatic' => $config->getThresholdAutomatic(),
                'threshold_manager' => $config->getThresholdManager(),
                'threshold_executive' => $config->getThresholdExecutive(),
            ];

            if ($isNew) {
                $this->auditLogger->logCreate('RiskApprovalConfig', $config->getId(), $newValues, 'Risk-Approval-Schwellwerte initial gesetzt.');
            } else {
                $this->auditLogger->logUpdate('RiskApprovalConfig', $config->getId(), $oldValues, $newValues, 'Risk-Approval-Schwellwerte aktualisiert.');
            }

            $this->addFlash('success', 'risk_approval_config.saved');
            return $this->redirectToRoute('app_admin_risk_approval_config');
        }

        return $this->render('admin/risk_approval_config/edit.html.twig', [
            'form' => $form,
            'config' => $config,
        ]);
    }
}
