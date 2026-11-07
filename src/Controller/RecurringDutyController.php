<?php

declare(strict_types=1);
// @em-write-allowed: one completion or one settings change per action on a tenant row; audit log via AuditLogSubscriber.

namespace App\Controller;

use App\Entity\Document;
use App\Entity\RecurringDuty;
use App\Entity\User;
use App\Form\RecurringDutyDoneType;
use App\Form\RecurringDutySettingsType;
use App\Service\Planning\Source\Adapter\RecurringDutyAdapter;
use App\Service\RecurringDuty\RecurringDutyCatalog;
use App\Service\RecurringDuty\RecurringDutyService;
use App\Service\Responsibility\ResponsibilityResolver;
use App\Service\TenantContext;
use App\Util\ListPage;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pflichtenkalender: the organisation's recurring duties without a date on a
 * record (risk acceptance criteria, BIA, BC exercise, SoA review, key
 * rotation, NIS-2 management training, TOM review, DORA register, …).
 * Whoever is responsible marks a duty done (with evidence); managers set
 * interval, owner and due date for their organisation.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/recurring-duties', name: 'app_recurring_duty_')]
#[IsGranted('ROLE_USER')]
final class RecurringDutyController extends AbstractController
{
    public function __construct(
        private readonly RecurringDutyService $duties,
        private readonly RecurringDutyAdapter $adapter,
        private readonly ResponsibilityResolver $responsibility,
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $duties = $tenant !== null ? $this->duties->applicable($tenant) : [];
        $completable = [];
        foreach ($duties as $duty) {
            $completable[(int) $duty->getId()] = $this->mayComplete($duty);
        }

        return $this->render('recurring_duty/index.html.twig', [
            'page' => ListPage::fromRequest(
                $duties,
                $request,
                fn (RecurringDuty $d): array => [
                    $this->adapter->titleOf($d),
                    RecurringDutyCatalog::get($d->getDutyKey())['norm'] ?? '',
                    $d->getOwner()?->getFullName() ?? '',
                ],
            ),
            'catalog' => RecurringDutyCatalog::DUTIES,
            'completable' => $completable,
            'today' => new DateTimeImmutable('today'),
        ]);
    }

    #[Route('/{id}/done', name: 'done', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function done(RecurringDuty $duty, Request $request): Response
    {
        $this->guard($duty);
        $user = $this->getUser();
        if (!$user instanceof User || !$this->mayComplete($duty)) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(RecurringDutyDoneType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{doneAt: DateTimeImmutable, evidence: ?Document, note: ?string} $data */
            $data = $form->getData();
            $duty->markDone($data['doneAt'], $user, $data['evidence'], $data['note']);
            $this->entityManager->flush();
            $this->addFlash('success', 'recurring_duty.flash.done');

            return $this->redirectToRoute('app_recurring_duty_index');
        }

        return $this->render('recurring_duty/done.html.twig', [
            'duty' => $duty,
            'title' => $this->adapter->titleOf($duty),
            'meta' => RecurringDutyCatalog::get($duty->getDutyKey()),
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_MANAGER')]
    public function edit(RecurringDuty $duty, Request $request): Response
    {
        $this->guard($duty);
        $form = $this->createForm(RecurringDutySettingsType::class, $duty);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'recurring_duty.flash.saved');

            return $this->redirectToRoute('app_recurring_duty_index');
        }

        return $this->render('recurring_duty/edit.html.twig', [
            'duty' => $duty,
            'title' => $this->adapter->titleOf($duty),
            'meta' => RecurringDutyCatalog::get($duty->getDutyKey()),
            'default_interval' => $this->duties->defaultInterval($duty->getDutyKey(), $duty->getTenant()),
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** The responsible person (owner, else the duty's roles) or a manager. */
    private function mayComplete(RecurringDuty $duty): bool
    {
        $user = $this->getUser();

        return $user instanceof User && ($this->isGranted('ROLE_MANAGER')
            || $this->responsibility->isResponsible($user, $this->adapter->responsibleOf($duty), $duty->getTenant()));
    }

    private function guard(RecurringDuty $duty): void
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $duty->getTenant()->getId() !== $tenant->getId()
            || !$this->duties->applies($duty->getDutyKey(), $tenant)) {
            throw new NotFoundHttpException();
        }
    }
}
