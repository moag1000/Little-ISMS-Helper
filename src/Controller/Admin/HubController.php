<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Admin\AdminHubCatalog;
use App\Service\ModuleConfigurationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
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
 * automatisch als Coming-Soon gerendert.
 *
 * Role-Scope Architecture Phase 2 (spec
 * `docs/superpowers/specs/2026-05-18-role-scope-architecture.md`):
 * Modules carry optional `requiredAttribute` / `requiredRole` /
 * `requiredModule` annotations. The hub filters them at render time so
 * users only see cards they have the auth + feature-flag to use.
 * Groups with zero visible modules are dropped — no empty section headers.
 */
#[IsGranted('ROLE_ADMIN')]
class HubController extends AbstractController
{
    public function __construct(
        private readonly AdminHubCatalog $catalog,
        private readonly RouterInterface $router,
        private readonly Security $security,
        private readonly ModuleConfigurationService $moduleConfiguration,
    ) {
    }

    #[Route('/admin/hub', name: 'admin_hub_index', methods: ['GET'])]
    public function index(): Response
    {
        $groups = $this->catalog->getGroups();

        $known = $this->knownRouteNames();
        foreach ($groups as &$group) {
            foreach ($group['modules'] as &$module) {
                if ($module['route'] !== null && !isset($known[$module['route']])) {
                    $module['coming_soon'] = true;
                    $module['route'] = null;
                }
            }
            unset($module);
        }
        unset($group);

        // Role-Scope Phase 2 — drop modules the user has no access to (or
        // whose feature-flag module is inactive). Groups with zero remaining
        // modules are dropped entirely so the hub doesn't render empty
        // section headers.
        foreach ($groups as &$group) {
            $group['modules'] = array_values(array_filter(
                $group['modules'],
                function (array $m): bool {
                    if (!empty($m['requiredAttribute']) && !$this->security->isGranted($m['requiredAttribute'])) {
                        return false;
                    }
                    if (!empty($m['requiredRole']) && !$this->security->isGranted($m['requiredRole'])) {
                        return false;
                    }
                    if (!empty($m['requiredModule']) && !$this->moduleConfiguration->isModuleActive($m['requiredModule'])) {
                        return false;
                    }
                    return true;
                }
            ));
        }
        unset($group);

        // Drop empty groups so the hub header doesn't render zero-card sections.
        $groups = array_values(array_filter($groups, static fn(array $g): bool => count($g['modules']) > 0));

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
