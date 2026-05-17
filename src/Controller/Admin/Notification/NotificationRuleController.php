<?php

declare(strict_types=1);

namespace App\Controller\Admin\Notification;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\Notification\NotificationRule;
use App\Entity\User;
use App\Form\Notification\NotificationRuleType;
use App\Repository\Notification\NotificationDeliveryRepository;
use App\Repository\Notification\NotificationRuleRepository;
use App\Security\Voter\Notification\NotificationRuleVoter;
use App\Service\AuditLogger;
use App\Service\ModuleConfigurationService;
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
 * CRUD controller for NotificationRule admin UI.
 *
 * Module-gate: notifications
 * Role-gate:   ROLE_MANAGER
 */
#[Route('/admin/notification/rule', name: 'admin_notification_rule_')]
#[IsGranted('ROLE_MANAGER')]
class NotificationRuleController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly NotificationRuleRepository $ruleRepository,
        private readonly NotificationDeliveryRepository $deliveryRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
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

        $tenant = $user->getTenant();

        $rules = $this->ruleRepository->findBy(
            ['tenant' => $tenant],
            ['name' => 'ASC']
        );

        return $this->render('admin/notification/rule/index.html.twig', [
            'rules' => $rules,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        if ($redirect = $this->checkModuleActive('notifications')) {
            return $redirect;
        }

        $tenant = $user->getTenant();

        $rule = new NotificationRule();
        $rule->setTenant($tenant);
        $rule->setCreatedBy($user);

        $form = $this->createForm(NotificationRuleType::class, $rule, [
            'tenant' => $tenant,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($rule);
            $this->entityManager->flush();

            $this->auditLogger->log(
                AuditLogger::ACTION_NOTIFICATION_RULE_CREATED,
                'NotificationRule',
                $rule->getId(),
                null,
                ['name' => $rule->getName(), 'eventType' => $rule->getEventType()],
                sprintf('Rule "%s" created', $rule->getName()),
            );

            $this->addFlash('success', $this->translator->trans(
                'notification.rule.flash.created',
                ['%name%' => $rule->getName()],
                'notification',
            ));

            return $this->redirectToRoute('admin_notification_rule_show', ['id' => $rule->getId()]);
        }

        return $this->render('admin/notification/rule/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(NotificationRule $rule): Response
    {
        if ($redirect = $this->checkModuleActive('notifications')) {
            return $redirect;
        }

        $this->denyAccessUnlessGranted(NotificationRuleVoter::VIEW, $rule);

        $recentDeliveries = $this->deliveryRepository->findRecentByRule($rule, 10);

        return $this->render('admin/notification/rule/show.html.twig', [
            'rule'             => $rule,
            'recentDeliveries' => $recentDeliveries,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        NotificationRule $rule,
        #[CurrentUser] User $user,
    ): Response {
        if ($redirect = $this->checkModuleActive('notifications')) {
            return $redirect;
        }

        $this->denyAccessUnlessGranted(NotificationRuleVoter::EDIT, $rule);

        $tenant = $user->getTenant();

        $oldValues = ['name' => $rule->getName(), 'eventType' => $rule->getEventType(), 'isActive' => $rule->isActive()];

        $form = $this->createForm(NotificationRuleType::class, $rule, [
            'tenant' => $tenant,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->auditLogger->log(
                AuditLogger::ACTION_NOTIFICATION_RULE_UPDATED,
                'NotificationRule',
                $rule->getId(),
                $oldValues,
                ['name' => $rule->getName(), 'eventType' => $rule->getEventType(), 'isActive' => $rule->isActive()],
                sprintf('Rule "%s" updated', $rule->getName()),
            );

            $this->addFlash('success', $this->translator->trans(
                'notification.rule.flash.updated',
                ['%name%' => $rule->getName()],
                'notification',
            ));

            return $this->redirectToRoute('admin_notification_rule_show', ['id' => $rule->getId()]);
        }

        return $this->render('admin/notification/rule/edit.html.twig', [
            'rule' => $rule,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/toggle', name: 'toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('notification_rule_toggle_{id}')]
    public function toggle(NotificationRule $rule): Response
    {
        if ($redirect = $this->checkModuleActive('notifications')) {
            return $redirect;
        }

        $this->denyAccessUnlessGranted(NotificationRuleVoter::EDIT, $rule);

        $wasActive = $rule->isActive();
        $rule->setIsActive(!$wasActive);
        $this->entityManager->flush();

        $action = $wasActive
            ? AuditLogger::ACTION_NOTIFICATION_RULE_DISABLED
            : AuditLogger::ACTION_NOTIFICATION_RULE_ENABLED;

        $this->auditLogger->log(
            $action,
            'NotificationRule',
            $rule->getId(),
            ['isActive' => $wasActive],
            ['isActive' => $rule->isActive()],
            sprintf('Rule "%s" %s', $rule->getName(), $rule->isActive() ? 'enabled' : 'disabled'),
        );

        $flashKey = $rule->isActive() ? 'notification.rule.flash.enabled' : 'notification.rule.flash.disabled';
        $this->addFlash('success', $this->translator->trans($flashKey, ['%name%' => $rule->getName()], 'notification'));

        return $this->redirectToRoute('admin_notification_rule_show', ['id' => $rule->getId()]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('notification_rule_delete_{id}')]
    public function delete(NotificationRule $rule): Response
    {
        if ($redirect = $this->checkModuleActive('notifications')) {
            return $redirect;
        }

        $this->denyAccessUnlessGranted(NotificationRuleVoter::DELETE, $rule);

        $oldValues = ['name' => $rule->getName(), 'id' => $rule->getId()];
        $this->entityManager->remove($rule);
        $this->entityManager->flush();

        $this->auditLogger->log(
            AuditLogger::ACTION_NOTIFICATION_RULE_DELETED,
            'NotificationRule',
            $oldValues['id'],
            $oldValues,
            null,
            sprintf('Rule "%s" deleted', $oldValues['name']),
        );

        $this->addFlash('success', $this->translator->trans(
            'notification.rule.flash.deleted',
            ['%name%' => $oldValues['name']],
            'notification',
        ));

        return $this->redirectToRoute('admin_notification_rule_index');
    }
}
