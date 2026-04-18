<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\KpiThresholdConfig;
use App\Entity\Tenant;
use App\Form\KpiThresholdConfigType;
use App\Repository\KpiThresholdConfigRepository;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/kpi-thresholds', name: 'admin_kpi_threshold_')]
class KpiThresholdConfigController extends AbstractController
{
    public function __construct(
        private readonly KpiThresholdConfigRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $configs = $tenant instanceof Tenant
            ? $this->repository->findBy(['tenant' => $tenant], ['kpiKey' => 'ASC'])
            : $this->repository->findAll();

        return $this->render('admin/kpi_threshold/index.html.twig', [
            'configs' => $configs,
            'tenant' => $tenant,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            $this->addFlash('error', 'kpi_threshold.flash.no_tenant');
            return $this->redirectToRoute('admin_kpi_threshold_index');
        }

        $config = new KpiThresholdConfig();
        $config->setTenant($tenant);

        $form = $this->createForm(KpiThresholdConfigType::class, $config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($config->getGoodThreshold() < $config->getWarningThreshold()) {
                $this->addFlash('error', 'kpi_threshold.flash.good_below_warning');
                return $this->render('admin/kpi_threshold/new.html.twig', ['form' => $form]);
            }
            try {
                $this->entityManager->persist($config);
                $this->entityManager->flush();
                $this->addFlash('success', 'kpi_threshold.flash.created');
                return $this->redirectToRoute('admin_kpi_threshold_index');
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', 'kpi_threshold.flash.duplicate_key');
            }
        }

        return $this->render('admin/kpi_threshold/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, KpiThresholdConfig $config): Response
    {
        $this->denyIfWrongTenant($config);

        $form = $this->createForm(KpiThresholdConfigType::class, $config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($config->getGoodThreshold() < $config->getWarningThreshold()) {
                $this->addFlash('error', 'kpi_threshold.flash.good_below_warning');
                return $this->render('admin/kpi_threshold/edit.html.twig', ['form' => $form, 'config' => $config]);
            }
            $config->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();
            $this->addFlash('success', 'kpi_threshold.flash.updated');
            return $this->redirectToRoute('admin_kpi_threshold_index');
        }

        return $this->render('admin/kpi_threshold/edit.html.twig', [
            'form' => $form,
            'config' => $config,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, KpiThresholdConfig $config): Response
    {
        $this->denyIfWrongTenant($config);

        if (!$this->isCsrfTokenValid('delete_kth_' . $config->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->entityManager->remove($config);
        $this->entityManager->flush();
        $this->addFlash('success', 'kpi_threshold.flash.deleted');

        return $this->redirectToRoute('admin_kpi_threshold_index');
    }

    private function denyIfWrongTenant(KpiThresholdConfig $config): void
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant || $config->getTenant()?->getId() !== $tenant->getId()) {
            if (!$this->isGranted('ROLE_SUPER_ADMIN')) {
                throw $this->createAccessDeniedException('Config belongs to a different tenant.');
            }
        }
    }
}
