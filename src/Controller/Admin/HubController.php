<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Admin\AdminHubCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings-Hub-Landing für /admin.
 *
 * Rendert ein Card-Grid mit 7 IA-Gruppen × ~36 Modulen gemäß
 * docs/design_system/sections/admin-panel.html. Die Module zeigen auf
 * existierende Admin-Routes; Module mit nicht-existenter Route werden
 * automatisch als Coming-Soon gerendert. Sprint 1 lässt die alte
 * Dashboard-Route /admin/legacy intakt — Mega-Menü wird in Sprint 4
 * umgehängt.
 */
#[IsGranted('ROLE_ADMIN')]
class HubController extends AbstractController
{
    public function __construct(
        private readonly AdminHubCatalog $catalog,
        private readonly RouterInterface $router,
    ) {
    }

    #[Route('/admin/hub', name: 'admin_hub_index', methods: ['GET'])]
    public function index(): Response
    {
        $groups = $this->catalog->getGroups();

        $known = $this->knownRouteNames();
        foreach ($groups as &$group) {
            // Filter out modules the current user lacks the required role for
            // — keeps the hub honest (no broken links to 403-walled pages).
            $group['modules'] = array_values(array_filter(
                $group['modules'],
                fn(array $m): bool => empty($m['requiredRole']) || $this->isGranted($m['requiredRole']),
            ));
            foreach ($group['modules'] as &$module) {
                if ($module['route'] !== null && !isset($known[$module['route']])) {
                    $module['coming_soon'] = true;
                    $module['route'] = null;
                }
            }
            unset($module);
        }
        unset($group);

        $totalModules = array_sum(array_map(static fn(array $g): int => count($g['modules']), $groups));

        return $this->render('admin/hub.html.twig', [
            'groups' => $groups,
            'total_modules' => $totalModules,
        ]);
    }

    /**
     * Kept extra-light: returns a hash-set of every defined route name so
     * the catalog can flag modules whose target route disappeared.
     *
     * @return array<string, true>
     */
    private function knownRouteNames(): array
    {
        $set = [];
        foreach ($this->router->getRouteCollection()->all() as $name => $_route) {
            $set[$name] = true;
        }
        return $set;
    }
}
