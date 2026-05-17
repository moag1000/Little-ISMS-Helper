<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ThreatIntelligence;
use App\Form\ThreatIntelligenceType;
use App\Repository\ThreatIntelligenceRepository;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class ThreatIntelligenceController extends AbstractController
{
    public function __construct(
        private readonly ThreatIntelligenceRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
    ) {}

    #[Route('/threat-intelligence/', name: 'app_threat_intelligence_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        $q          = trim((string) $request->query->get('q', ''));
        $severity   = $request->query->get('severity');
        $threatType = $request->query->get('threatType');
        $status     = $request->query->get('status');

        $user   = $this->security->getUser();
        $tenant = $user?->getTenant();

        $criteria = $tenant ? ['tenant' => $tenant] : [];
        $threats  = $this->repository->findBy($criteria, ['detectionDate' => 'DESC']);

        if ($severity !== null && $severity !== '') {
            $threats = array_filter($threats, fn(ThreatIntelligence $t): bool => $t->getSeverity() === $severity);
        }

        if ($threatType !== null && $threatType !== '') {
            $threats = array_filter($threats, fn(ThreatIntelligence $t): bool => $t->getThreatType() === $threatType);
        }

        if ($status !== null && $status !== '') {
            $threats = array_filter($threats, fn(ThreatIntelligence $t): bool => $t->getStatus() === $status);
        }

        if ($q !== '') {
            $needle  = mb_strtolower($q);
            $threats = array_filter($threats, function (ThreatIntelligence $t) use ($needle): bool {
                $haystack = mb_strtolower(
                    ($t->getTitle() ?? '')
                    . ' ' . ($t->getDescription() ?? '')
                    . ' ' . ($t->getThreatType() ?? '')
                    . ' ' . ($t->getCveId() ?? '')
                    . ' ' . ($t->getSource() ?? '')
                );
                return str_contains($haystack, $needle);
            });
        }

        $threats = array_values($threats);

        $stats = [
            'total'    => count($threats),
            'critical' => count(array_filter($threats, fn(ThreatIntelligence $t): bool => $t->getSeverity() === 'critical')),
            'high'     => count(array_filter($threats, fn(ThreatIntelligence $t): bool => $t->getSeverity() === 'high')),
            'open'     => count(array_filter($threats, fn(ThreatIntelligence $t): bool => !in_array($t->getStatus(), ['closed', 'mitigated'], true))),
        ];

        return $this->render('threat_intelligence/index.html.twig', [
            'threats' => $threats,
            'stats'   => $stats,
        ]);
    }

    #[Route('/threat-intelligence/new', name: 'app_threat_intelligence_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function new(Request $request): Response
    {
        $threat = new ThreatIntelligence();
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant !== null) {
            $threat->setTenant($tenant);
        }

        $form = $this->createForm(ThreatIntelligenceType::class, $threat);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($threat);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('threat.action.created_flash', [], 'threat'));
            return $this->redirectToRoute('app_threat_intelligence_show', ['id' => $threat->getId()]);
        }

        return $this->render('threat_intelligence/new.html.twig', [
            'threat' => $threat,
            'form'   => $form,
        ]);
    }

    #[Route('/threat-intelligence/{id}', name: 'app_threat_intelligence_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(ThreatIntelligence $threat): Response
    {
        return $this->render('threat_intelligence/show.html.twig', [
            'threat' => $threat,
        ]);
    }

    #[Route('/threat-intelligence/{id}/edit', name: 'app_threat_intelligence_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function edit(Request $request, ThreatIntelligence $threat): Response
    {
        $form = $this->createForm(ThreatIntelligenceType::class, $threat);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $threat->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('threat.action.updated_flash', [], 'threat'));
            return $this->redirectToRoute('app_threat_intelligence_show', ['id' => $threat->getId()]);
        }

        return $this->render('threat_intelligence/edit.html.twig', [
            'threat' => $threat,
            'form'   => $form,
        ]);
    }

    #[Route('/threat-intelligence/{id}/delete', name: 'app_threat_intelligence_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, ThreatIntelligence $threat): Response
    {
        if ($this->isCsrfTokenValid('delete' . $threat->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($threat);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('threat.action.deleted_flash', [], 'threat'));
        }

        return $this->redirectToRoute('app_threat_intelligence_index');
    }
}
