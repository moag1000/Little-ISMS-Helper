<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use Symfony\Component\HttpFoundation\Response;

/**
 * Provides whole-form module gating for controllers.
 *
 * Requires the using class to have:
 *   - $this->moduleService  (ModuleConfigurationService)
 *   - $this->translator     (TranslatorInterface)
 *   - $this->addFlash()     (from AbstractController)
 *   - $this->redirectToRoute() (from AbstractController)
 */
trait ModuleGatedControllerTrait
{
    /**
     * Returns a redirect Response if the given module is inactive, null otherwise.
     * Call at the top of every action method to gate access:
     *
     *   if ($redirect = $this->checkModuleActive('privacy')) return $redirect;
     */
    private function checkModuleActive(string $moduleKey): ?Response
    {
        if (!$this->moduleService->isModuleActive($moduleKey)) {
            $this->addFlash(
                'warning',
                $this->translator->trans(
                    'common.module_not_active',
                    ['%module%' => $moduleKey],
                    'messages'
                )
            );

            return $this->redirectToRoute('app_dashboard');
        }

        return null;
    }
}
