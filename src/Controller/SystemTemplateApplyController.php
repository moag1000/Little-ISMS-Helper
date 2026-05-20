<?php

declare(strict_types=1);

namespace App\Controller;

use App\Template\SystemTemplateApplier;
use App\Template\SystemTemplateRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Apply-endpoint for SystemTemplates (Foundation P-14).
 *
 *  POST /{locale}/system-templates/apply
 *      _token: <CSRF>
 *      template_key: <key>
 *      redirect_to: <route> (optional, default app_dashboard)
 *
 * Permissions: ROLE_MANAGER+ — applying templates is a tenant-scoped data
 * mutation. The Applier enforces tenant binding; the controller adds CSRF
 * + role checks + flash messages.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/system-templates')]
final class SystemTemplateApplyController extends AbstractController
{
    public function __construct(
        private readonly SystemTemplateRegistry $registry,
        private readonly SystemTemplateApplier $applier,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/apply', name: 'app_system_template_apply', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function apply(Request $request): Response
    {
        $token = (string) $request->request->get('_token', '');
        $templateKey = (string) $request->request->get('template_key', '');
        $redirectTo = (string) $request->request->get('redirect_to', 'app_dashboard');

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('system_template_apply', $token))) {
            return $this->respondError($request, 'system_templates.error.csrf_invalid', 400);
        }

        $template = $this->registry->get($templateKey);
        if ($template === null) {
            return $this->respondError($request, 'system_templates.error.unknown_key', 404);
        }

        try {
            $entities = $this->applier->apply($template);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            return $this->respondError(
                $request,
                'system_templates.error.apply_failed',
                500,
                ['%message%' => $e->getMessage()],
            );
        }

        $result = $this->applier->lastResult();
        $count = $result['records'] ?? count($entities);

        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse([
                'success' => true,
                'records_created' => $count,
                'profile_applied' => $result['profile_applied'] ?? null,
            ]);
        }

        $this->addFlash(
            'success',
            $this->translator->trans(
                'system_templates.success.applied',
                ['%name%' => $template->name, '%count%' => $count],
                'system_templates',
            ),
        );

        return $this->safeRedirect($redirectTo);
    }

    /**
     * @param array<string, string> $translationParams
     */
    private function respondError(
        Request $request,
        string $key,
        int $status,
        array $translationParams = [],
    ): Response {
        $message = $this->translator->trans($key, $translationParams, 'system_templates');

        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['success' => false, 'error' => $message], $status);
        }

        $this->addFlash('danger', $message);
        return $this->redirectToRoute('app_dashboard');
    }

    private function safeRedirect(string $route): RedirectResponse
    {
        try {
            return $this->redirectToRoute($route);
        } catch (\Throwable) {
            return $this->redirectToRoute('app_dashboard');
        }
    }
}
