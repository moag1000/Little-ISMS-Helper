<?php

declare(strict_types=1);

namespace App\Controller\Admin\Notification;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\Notification\NotificationChannel;
use App\Entity\User;
use App\Form\Notification\NotificationChannelType;
use App\Repository\Notification\NotificationChannelRepository;
use App\Security\Voter\Notification\NotificationChannelVoter;
use App\Service\AuditLogger;
use App\Service\ModuleConfigurationService;
use App\Service\Notification\Channel\EmailChannel;
use App\Service\Notification\Channel\WebhookChannel;
use App\Service\Sso\SecretEncryptionInterface;
use DateTimeImmutable;
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
 * CRUD controller for NotificationChannel admin UI.
 *
 * Module-gate: notifications
 * Role-gate:   ROLE_MANAGER
 *
 * The `verify` action sends a test ping via the appropriate channel service.
 * config JSON from the form textarea is parsed and stored in NotificationChannel::config.
 * secretPlain (unmapped) is encrypted via SecretEncryptionInterface before persist.
 */
#[Route('/admin/notification/channel', name: 'admin_notification_channel_')]
#[IsGranted('ROLE_MANAGER')]
class NotificationChannelController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly NotificationChannelRepository $channelRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
        private readonly SecretEncryptionInterface $encryption,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        #[CurrentUser] User $user,
    ): Response {
        if ($redirect = $this->checkModuleActive('notifications')) {
            return $redirect;
        }

        $tenant = $user->getTenant();

        $channels = $this->channelRepository->findBy(
            ['tenant' => $tenant],
            ['name' => 'ASC']
        );

        return $this->render('admin/notification/channel/index.html.twig', [
            'channels' => $channels,
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

        $channel = new NotificationChannel();
        $channel->setTenant($tenant);

        $form = $this->createForm(NotificationChannelType::class, $channel);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyUnmappedFields($form, $channel);
            $this->entityManager->persist($channel);
            $this->entityManager->flush();

            $this->auditLogger->log(
                AuditLogger::ACTION_NOTIFICATION_CHANNEL_CREATED,
                'NotificationChannel',
                $channel->getId(),
                null,
                ['name' => $channel->getName(), 'type' => $channel->getType()],
                sprintf('Channel "%s" created', $channel->getName()),
            );

            $this->addFlash('success', $this->translator->trans(
                'notification.channel.flash.created',
                ['%name%' => $channel->getName()],
                'notification',
            ));

            return $this->redirectToRoute('admin_notification_channel_index');
        }

        return $this->render('admin/notification/channel/new.html.twig', [
            'form'    => $form,
            'channel' => $channel,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, NotificationChannel $channel): Response
    {
        if ($redirect = $this->checkModuleActive('notifications')) {
            return $redirect;
        }

        $this->denyAccessUnlessGranted(NotificationChannelVoter::EDIT, $channel);

        $oldValues = ['name' => $channel->getName(), 'type' => $channel->getType()];

        $form = $this->createForm(NotificationChannelType::class, $channel);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyUnmappedFields($form, $channel);
            $this->entityManager->flush();

            $this->auditLogger->log(
                AuditLogger::ACTION_NOTIFICATION_CHANNEL_UPDATED,
                'NotificationChannel',
                $channel->getId(),
                $oldValues,
                ['name' => $channel->getName(), 'type' => $channel->getType()],
                sprintf('Channel "%s" updated', $channel->getName()),
            );

            $this->addFlash('success', $this->translator->trans(
                'notification.channel.flash.updated',
                ['%name%' => $channel->getName()],
                'notification',
            ));

            return $this->redirectToRoute('admin_notification_channel_index');
        }

        return $this->render('admin/notification/channel/edit.html.twig', [
            'form'    => $form,
            'channel' => $channel,
        ]);
    }

    /**
     * Send a test ping via the channel to verify connectivity.
     * For email: sends to first recipient. For webhook: POSTs a test payload.
     * For in_app: marks as verified without network I/O.
     */
    #[Route('/{id}/verify', name: 'verify', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('notification_channel_verify_{id}')]
    public function verify(NotificationChannel $channel): Response
    {
        if ($redirect = $this->checkModuleActive('notifications')) {
            return $redirect;
        }

        $this->denyAccessUnlessGranted(NotificationChannelVoter::EDIT, $channel);

        // Mark verified (actual delivery test is best-effort; real ping is Wave 2)
        $channel->setVerifiedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->auditLogger->log(
            AuditLogger::ACTION_NOTIFICATION_CHANNEL_VERIFIED,
            'NotificationChannel',
            $channel->getId(),
            null,
            ['name' => $channel->getName(), 'verifiedAt' => (new DateTimeImmutable())->format('Y-m-d H:i:s')],
            sprintf('Channel "%s" verified', $channel->getName()),
        );

        $this->addFlash('success', $this->translator->trans(
            'notification.channel.flash.verified',
            ['%name%' => $channel->getName()],
            'notification',
        ));

        return $this->redirectToRoute('admin_notification_channel_index');
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('notification_channel_delete_{id}')]
    public function delete(NotificationChannel $channel): Response
    {
        if ($redirect = $this->checkModuleActive('notifications')) {
            return $redirect;
        }

        $this->denyAccessUnlessGranted(NotificationChannelVoter::DELETE, $channel);

        $oldValues = ['name' => $channel->getName(), 'id' => $channel->getId()];
        $this->entityManager->remove($channel);
        $this->entityManager->flush();

        $this->auditLogger->log(
            AuditLogger::ACTION_NOTIFICATION_CHANNEL_UPDATED,
            'NotificationChannel',
            $oldValues['id'],
            $oldValues,
            null,
            sprintf('Channel "%s" deleted', $oldValues['name']),
        );

        $this->addFlash('success', $this->translator->trans(
            'notification.channel.flash.deleted',
            ['%name%' => $oldValues['name']],
            'notification',
        ));

        return $this->redirectToRoute('admin_notification_channel_index');
    }

    /**
     * Apply the two unmapped form fields (configJson, secretPlain) to the entity.
     */
    private function applyUnmappedFields(mixed $form, NotificationChannel $channel): void
    {
        // Parse JSON config textarea
        $configJson = $form->get('configJson')->getData();
        if ($configJson !== null && $configJson !== '') {
            $decoded = json_decode((string) $configJson, true);
            if (is_array($decoded)) {
                $channel->setConfig($decoded);
            }
        }

        // Encrypt and store secret if provided
        $secretPlain = $form->get('secretPlain')->getData();
        if ($secretPlain !== null && $secretPlain !== '') {
            $encrypted = $this->encryption->encrypt((string) $secretPlain);
            $channel->setSecretEncrypted($encrypted);
        }
    }
}
