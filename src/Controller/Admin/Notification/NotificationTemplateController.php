<?php

declare(strict_types=1);

namespace App\Controller\Admin\Notification;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\Notification\NotificationTemplate;
use App\Entity\User;
use App\Repository\Notification\NotificationTemplateRepository;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\ModuleConfigurationService;
use App\Service\Notification\TemplateInstantiator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gallery controller for Tier-1 NotificationTemplate objects.
 *
 * Index — shows all global templates available to the current tenant.
 * Apply  — instantiates the selected template as a new NotificationRule
 *           via TemplateInstantiator, then redirects to the rule-edit page.
 *
 * Module-gate: notifications
 * Role-gate:   {@see TenantScopedAdminVoter::ADMIN_OWN_TENANT} — ROLE_ADMIN
 *              (own-tenant) + ROLE_SUPER_ADMIN (any tenant). The gallery is
 *              read-only at this level; `apply` materializes a tenant rule.
 *              The underlying global template library itself is curated via a
 *              separate global-op surface (out of scope for Phase 4d).
 *
 * Migrated from `ROLE_MANAGER` in Phase 4d of the Role-Scope Architecture
 * rollout (spec: `docs/superpowers/specs/2026-05-18-role-scope-architecture.md`).
 */
#[Route('/admin/notification/template', name: 'admin_notification_template_')]
#[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
class NotificationTemplateController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly NotificationTemplateRepository $templateRepository,
        private readonly TemplateInstantiator $instantiator,
        private readonly EntityManagerInterface $entityManager,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        #[CurrentUser] User $user,
    ): Response {
        if ($redirect = $this->checkModuleActive('notifications')) {
            return $redirect;
        }

        $templates = $this->templateRepository->findAvailableForTenant($user->getTenant());

        return $this->render('admin/notification/template/index.html.twig', [
            'templates' => $templates,
        ]);
    }

    #[Route('/{id}/apply', name: 'apply', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('notification_template_apply_{id}')]
    public function apply(
        NotificationTemplate $template,
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        if ($redirect = $this->checkModuleActive('notifications')) {
            return $redirect;
        }

        $tenant = $user->getTenant();

        if ($tenant === null) {
            $this->addFlash('danger', $this->translator->trans('notification.template.flash.no_tenant', [], 'notification'));
            return $this->redirectToRoute('admin_notification_template_index');
        }

        $rule = $this->instantiator->instantiate($template, $tenant);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans(
            'notification.template.flash.applied',
            ['%template%' => $template->getName()],
            'notification',
        ));

        return $this->redirectToRoute('admin_notification_rule_edit', ['id' => $rule->getId()]);
    }
}
