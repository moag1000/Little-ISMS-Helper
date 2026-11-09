<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresModule;
use App\Controller\Trait\LocalizedFlashTrait;
use App\Entity\ThreatLedPenetrationTest;
use App\Form\ThreatLedPenetrationTestType;
use App\Repository\ThreatLedPenetrationTestRepository;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use App\Util\ListPage;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

// @em-write-allowed: plain CRUD of one record; status changes go through the lifecycle (LifecycleController).

/**
 * DORA Art. 26 threat-led penetration tests (TLPT): register of the tests,
 * one page per test with the lifecycle (planned → scoping → red_team →
 * reporting → closed), the findings it produced and the history.
 *
 * Reading: every member of the organisation. Planning and editing: manager
 * scope (CISO, ISB, …) — the people the TLPT reminder goes to. The actual
 * test dates are stamped by the lifecycle steps, not typed in.
 */
#[RequiresModule('nis2_dora')]
#[IsGranted('ROLE_USER')]
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/tlpt', name: 'app_tlpt_')]
class ThreatLedPenetrationTestController extends AbstractController
{
    use LocalizedFlashTrait;

    /** States in which the test is finished and the record is evidence. */
    private const array FINAL_STATES = [ThreatLedPenetrationTest::STATUS_CLOSED, ThreatLedPenetrationTest::STATUS_CANCELLED];

    public function __construct(
        private readonly ThreatLedPenetrationTestRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    protected function getFlashDomain(): string
    {
        return 'dora';
    }

    protected function getTranslator(): TranslatorInterface
    {
        return $this->translator;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $tests = $tenant === null ? [] : $this->repository->findBy(['tenant' => $tenant], ['plannedStartDate' => \SortDirection::Descending, 'id' => \SortDirection::Descending]);

        $counts = ['running' => 0, 'closed' => 0];
        foreach ($tests as $test) {
            if ($test->getStatus() === ThreatLedPenetrationTest::STATUS_CLOSED) {
                ++$counts['closed'];
            } elseif ($test->getStatus() !== ThreatLedPenetrationTest::STATUS_CANCELLED) {
                ++$counts['running'];
            }
        }

        return $this->render('tlpt/index.html.twig', [
            'page' => ListPage::fromRequest(
                $tests,
                $request,
                static fn (ThreatLedPenetrationTest $t): array => [$t->getEngagementNumber(), $t->getTitle(), $t->getTestProvider()],
            ),
            'counts' => $counts,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function new(Request $request): Response
    {
        $test = new ThreatLedPenetrationTest();
        $test->setTenant($this->tenantContext->getCurrentTenant());
        $test->setEngagementNumber($this->nextEngagementNumber());

        $form = $this->createForm(ThreatLedPenetrationTestType::class, $test);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($test);
            $this->entityManager->flush();
            $this->auditLogger->logCreate('ThreatLedPenetrationTest', $test->getId(), $this->auditValues($test));
            $this->flashSuccess('dora.tlpt.flash.created');

            return $this->redirectToRoute('app_tlpt_show', ['id' => $test->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('tlpt/form.html.twig', [
            'form' => $form,
            'tlpt' => $test,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ThreatLedPenetrationTest $tlpt): Response
    {
        $this->ownOrNotFound($tlpt);
        return $this->render('tlpt/show.html.twig', [
            'tlpt' => $tlpt,
            'editable' => !in_array($tlpt->getStatus(), self::FINAL_STATES, true),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function edit(Request $request, ThreatLedPenetrationTest $tlpt): Response
    {
        $this->ownOrNotFound($tlpt);
        if (in_array($tlpt->getStatus(), self::FINAL_STATES, true)) {
            $this->flashWarning('dora.tlpt.flash.final');

            return $this->redirectToRoute('app_tlpt_show', ['id' => $tlpt->getId()]);
        }

        $before = $this->auditValues($tlpt);
        $form = $this->createForm(ThreatLedPenetrationTestType::class, $tlpt);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $tlpt->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();
            $this->auditLogger->logUpdate('ThreatLedPenetrationTest', $tlpt->getId(), $before, $this->auditValues($tlpt));
            $this->flashSuccess('dora.tlpt.flash.updated');

            return $this->redirectToRoute('app_tlpt_show', ['id' => $tlpt->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('tlpt/form.html.twig', [
            'form' => $form,
            'tlpt' => $tlpt,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** Only a test that never started can be deleted; a started one is cancelled (with a reason). */
    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid(new Expression('"tlpt_delete_" ~ args["tlpt"].getId()'))]
    public function delete(ThreatLedPenetrationTest $tlpt): Response
    {
        $this->ownOrNotFound($tlpt);
        if ($tlpt->getStatus() !== ThreatLedPenetrationTest::STATUS_PLANNED) {
            $this->flashWarning('dora.tlpt.flash.delete_only_planned');

            return $this->redirectToRoute('app_tlpt_show', ['id' => $tlpt->getId()]);
        }

        $id = $tlpt->getId();
        $values = $this->auditValues($tlpt);
        $this->entityManager->remove($tlpt);
        $this->entityManager->flush();
        $this->auditLogger->logDelete('ThreatLedPenetrationTest', $id, $values);
        $this->flashSuccess('dora.tlpt.flash.deleted');

        return $this->redirectToRoute('app_tlpt_index', [], Response::HTTP_SEE_OTHER);
    }

    /** Another organisation's test does not exist for this user (the tenant filter is not relied on). */
    private function ownOrNotFound(ThreatLedPenetrationTest $tlpt): void
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $tlpt->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException();
        }
    }

    /** TLPT-<year>-<n>: the next free number of the organisation in this year. */
    private function nextEngagementNumber(): string
    {
        $prefix = 'TLPT-' . date('Y') . '-';
        $taken = [];
        $tenant = $this->tenantContext->getCurrentTenant();
        foreach ($tenant === null ? [] : $this->repository->findBy(['tenant' => $tenant]) as $test) {
            $taken[(string) $test->getEngagementNumber()] = true;
        }
        $n = 1;
        while (isset($taken[sprintf('%s%02d', $prefix, $n)])) {
            ++$n;
        }

        return sprintf('%s%02d', $prefix, $n);
    }

    /** @return array<string, mixed> */
    private function auditValues(ThreatLedPenetrationTest $test): array
    {
        return [
            'engagementNumber' => $test->getEngagementNumber(),
            'title' => $test->getTitle(),
            'providerType' => $test->getProviderType(),
            'testProvider' => $test->getTestProvider(),
            'plannedStartDate' => $test->getPlannedStartDate()?->format('Y-m-d'),
            'plannedEndDate' => $test->getPlannedEndDate()?->format('Y-m-d'),
            'jurisdictionCodes' => $test->getJurisdictionCodes(),
            'findings' => array_map(static fn ($f): ?int => $f->getId(), $test->getFindings()->toArray()),
        ];
    }
}
