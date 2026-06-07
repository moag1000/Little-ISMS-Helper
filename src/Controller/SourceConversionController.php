<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\SourceConversionConfig;
use App\Entity\Tenant;
use App\Repository\SourceConversionConfigRepository;
use App\Service\ModuleConfigurationService;
use App\Service\Planning\Source\SourceAdapterRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Per-source auto-conversion settings — which source modules feed the
 * Maßnahmen collector hub, with per-source due-offset + default effort.
 * Off-by-default; admin opt-in (Engineering-Spec §8).
 */
final class SourceConversionController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly SourceAdapterRegistry $registry,
        private readonly SourceConversionConfigRepository $configRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ModuleConfigurationService $moduleService,
    ) {
    }

    #[Route('/planning/sources', name: 'app_planning_sources_index', methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        $tenant = $this->security->getUser()?->getTenant();
        $configs = $tenant instanceof Tenant
            ? $this->configRepository->findForTenantKeyedBySlug($tenant)
            : [];

        $rows = [];
        foreach ($this->registry->all() as $adapter) {
            $slug = $adapter->slug();
            $config = $configs[$slug] ?? null;
            $rows[] = [
                'slug' => $slug,
                'label' => $adapter->label(),
                'module' => $adapter->requiredModule(),
                'enabled' => $config?->isEnabled() ?? false,
                'offset' => $config?->getDueOffsetDays() ?? 0,
                'effort' => $config?->getDefaultEffortPt(),
            ];
        }

        return $this->render('planning/sources/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/planning/sources/{slug}', name: 'app_planning_sources_save', requirements: ['slug' => '[a-z_]+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function save(Request $request, string $slug): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) return $redirect;

        if (!$this->registry->has($slug)) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('sources' . $slug, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $tenant = $this->security->getUser()?->getTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createAccessDeniedException();
        }

        $config = $this->configRepository->findForTenantAndSlug($tenant, $slug);
        if ($config === null) {
            $config = new SourceConversionConfig();
            $config->setSourceSlug($slug)->setTenant($tenant);
            $this->entityManager->persist($config);
        }

        $config->setEnabled($request->request->getBoolean('enabled'));
        $config->setDueOffsetDays($request->request->getInt('due_offset_days'));
        $effort = trim((string) $request->request->get('default_effort_pt'));
        $config->setDefaultEffortPt($effort === '' ? null : $effort);

        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('planning.sources.success.saved', [], 'planning'));

        return $this->redirectToRoute('app_planning_sources_index');
    }
}
