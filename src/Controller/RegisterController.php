<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\IsmsRegisterEntry;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RegisterType;
use App\Form\IsmsRegisterEntryType;
use App\Repository\InterestedPartyRepository;
use App\Repository\IsmsRegisterEntryRepository;
use App\Service\ModuleConfigurationService;
use App\Service\Register\CompetenceGapAnalysis;
use App\Service\Register\IsmsScopeSummary;
use App\Service\Register\RegisterCatalog;
use App\Service\Register\RegisterEntryService;
use App\Service\Register\RegisterLinkTypes;
use App\Service\Register\RegisterProposalAdopter;
use App\Service\Register\RegisterProposalProvider;
use App\Service\TenantContext;
use App\Util\CsvSanitizer;
use App\Util\ListPage;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The norm registers (Paket 9): one overview of every register ISO 27001 and
 * NIS-2 expect, and one list / form / detail page for all generic registers.
 * Reading is open to every user (auditors included), writing needs ROLE_MANAGER
 * (every persona role inherits it).
 */
#[IsGranted('ROLE_USER')]
final class RegisterController extends AbstractController
{
    private const string TYPE_PATTERN = 'issue_external|issue_internal|opportunity|legal_requirement|scope_interface|role|competence|communication';

    public function __construct(
        private readonly IsmsRegisterEntryRepository $entries,
        private readonly RegisterCatalog $catalog,
        private readonly RegisterLinkTypes $linkTypes,
        private readonly RegisterProposalProvider $proposals,
        private readonly RegisterProposalAdopter $adopter,
        private readonly CompetenceGapAnalysis $competences,
        private readonly IsmsScopeSummary $scopeSummary,
        private readonly InterestedPartyRepository $parties,
        private readonly ModuleConfigurationService $modules,
        private readonly TenantContext $tenantContext,
        private readonly RegisterEntryService $writer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/register', name: 'app_register_index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->tenant();

        return $this->render('register/overview.html.twig', [
            'rows' => $this->catalog->rows($tenant),
            'gaps' => $this->competences->gaps($tenant),
            'scope' => $this->scopeSummary->summarize($tenant),
        ]);
    }

    #[Route('/register/{type}', name: 'app_register_list', requirements: ['type' => self::TYPE_PATTERN], methods: ['GET'])]
    public function list(Request $request, string $type): Response
    {
        $registerType = RegisterType::from($type);
        $tenant = $this->tenant();
        $archived = $request->query->get('archived') === '1';
        $entries = $this->entries->findByType($tenant, $registerType, $archived);
        if ($archived) {
            $entries = array_values(array_filter($entries, static fn (IsmsRegisterEntry $e): bool => $e->isArchived()));
        }

        return $this->render('register/index.html.twig', [
            'type' => $registerType,
            'archived' => $archived,
            'page' => ListPage::fromRequest(
                $entries,
                $request,
                static fn (IsmsRegisterEntry $e): array => [(string) $e->getTitle(), (string) $e->getDescription(), (string) $e->getReference()],
            ),
            'proposals' => $this->isGranted('ROLE_MANAGER') ? $this->proposals->proposalsFor($tenant, $registerType) : [],
            'gaps' => $registerType === RegisterType::ROLE ? $this->gapCountByRole($tenant) : [],
            'partySchedule' => $registerType === RegisterType::COMMUNICATION && $this->modules->isModuleActive('interested_parties')
                ? $this->partySchedule($tenant)
                : [],
            'scope' => $registerType === RegisterType::SCOPE_INTERFACE ? $this->scopeSummary->summarize($tenant) : [],
        ]);
    }

    #[Route('/register/{type}/proposals', name: 'app_register_proposals_adopt', requirements: ['type' => self::TYPE_PATTERN], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid('register_adopt')]
    public function adopt(Request $request, string $type): Response
    {
        $registerType = RegisterType::from($type);
        $tenant = $this->tenant();
        $chosen = array_flip(array_map('strval', $request->request->all('origins')));
        $selected = array_values(array_filter(
            $this->proposals->proposalsFor($tenant, $registerType),
            static fn ($p): bool => isset($chosen[$p->origin]),
        ));
        if ($selected === []) {
            $this->addFlash('warning', 'register.flash.nothing_selected');
        } else {
            $user = $this->getUser();
            $created = $this->adopter->adopt($tenant, $selected, $user instanceof User ? $user : null);
            $this->addFlash('success', $this->translator->trans('register.flash.adopted', ['%count%' => count($created)], 'register'));
        }

        return $this->redirectToRoute('app_register_list', ['type' => $registerType->value]);
    }

    #[Route('/register/{type}/new', name: 'app_register_new', requirements: ['type' => self::TYPE_PATTERN], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function new(Request $request, string $type): Response
    {
        $registerType = RegisterType::from($type);
        $entry = (new IsmsRegisterEntry($registerType))
            ->setTenant($this->tenant())
            ->setNextReviewDate(new DateTimeImmutable(sprintf('today +%d months', $registerType->reviewMonths())));
        $user = $this->getUser();
        if ($user instanceof User && $registerType !== RegisterType::ROLE) {
            $entry->setOwnerUser($user);
        }
        $form = $this->createForm(IsmsRegisterEntryType::class, $entry, ['register_type' => $registerType]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->writer->save($entry);
            $this->addFlash('success', 'register.flash.created');

            return $this->redirectToRoute('app_register_entry_show', ['id' => $entry->getId()]);
        }

        return $this->renderForm($entry, $form);
    }

    #[Route('/register/entry/{id}', name: 'app_register_entry_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(IsmsRegisterEntry $entry): Response
    {
        $this->assertOwnTenant($entry);
        $reverseField = RegisterLinkTypes::entryFieldFor($entry->getType());

        return $this->render('register/show.html.twig', [
            'entry' => $entry,
            'type' => $entry->getType(),
            'links' => $this->linkTypes->groupsOf($entry),
            'referencedBy' => $reverseField !== null
                ? $this->entries->findLinkingTo($this->tenant(), $reverseField, (int) $entry->getId())
                : [],
            'competences' => $this->competences->forRole($entry),
        ]);
    }

    #[Route('/register/entry/{id}/edit', name: 'app_register_entry_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function edit(Request $request, IsmsRegisterEntry $entry): Response
    {
        $this->assertOwnTenant($entry);
        $form = $this->createForm(IsmsRegisterEntryType::class, $entry, ['register_type' => $entry->getType()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->writer->save($entry);
            $this->addFlash('success', 'register.flash.saved');

            return $this->redirectToRoute('app_register_entry_show', ['id' => $entry->getId()]);
        }

        return $this->renderForm($entry, $form);
    }

    #[Route('/register/entry/{id}/reviewed', name: 'app_register_entry_reviewed', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid(new Expression('"register_reviewed_" ~ args["entry"].getId()'))]
    public function reviewed(IsmsRegisterEntry $entry): Response
    {
        $this->assertOwnTenant($entry);
        $this->writer->markReviewed($entry);
        $this->addFlash('success', 'register.flash.reviewed');

        return $this->redirectToRoute('app_register_entry_show', ['id' => $entry->getId()]);
    }

    #[Route('/register/entry/{id}/archive', name: 'app_register_entry_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid(new Expression('"register_archive_" ~ args["entry"].getId()'))]
    public function archive(IsmsRegisterEntry $entry): Response
    {
        $this->assertOwnTenant($entry);
        $archived = $this->writer->toggleArchive($entry);
        $this->addFlash('success', $archived ? 'register.flash.archived' : 'register.flash.restored');

        return $this->redirectToRoute('app_register_entry_show', ['id' => $entry->getId()]);
    }

    #[Route('/register/entry/{id}/delete', name: 'app_register_entry_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsCsrfTokenValid(new Expression('"register_delete_" ~ args["entry"].getId()'))]
    public function delete(IsmsRegisterEntry $entry): Response
    {
        $this->assertOwnTenant($entry);
        $type = $entry->getType();
        $this->writer->delete($entry);
        $this->addFlash('success', 'register.flash.deleted');

        return $this->redirectToRoute('app_register_list', ['type' => $type->value]);
    }

    /** All generic registers as one CSV — the register extract for the audit. */
    #[Route('/register/export.csv', name: 'app_register_export', methods: ['GET'], priority: 10)]
    public function export(): StreamedResponse
    {
        $tenant = $this->tenant();
        $rows = [];
        foreach (RegisterType::cases() as $type) {
            foreach ($this->entries->findByType($tenant, $type) as $entry) {
                $rows[] = $entry;
            }
        }
        $t = fn (string $key): string => $this->translator->trans($key, [], 'register');
        $response = new StreamedResponse(function () use ($rows, $t): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                $t('register.csv.register'), $t('register.csv.norm'), $t('register.csv.title'),
                $t('register.csv.description'), $t('register.csv.category'), $t('register.csv.reference'),
                $t('register.csv.owner'), $t('register.csv.next_review'), $t('register.csv.last_reviewed'),
            ], ';', '"', '');
            foreach ($rows as $entry) {
                $type = $entry->getType();
                fputcsv($out, array_map(CsvSanitizer::sanitize(...), [
                    $t('register.type.' . $type->value . '.title'),
                    $type->normReference(),
                    $entry->getTitle(),
                    $entry->getDescription(),
                    $entry->getCategory() !== null ? $t('register.type.' . $type->value . '.category.' . $entry->getCategory()) : '',
                    $entry->getReference(),
                    $entry->getEffectiveOwner(),
                    $entry->getNextReviewDate()?->format('Y-m-d'),
                    $entry->getLastReviewedAt()?->format('Y-m-d'),
                ]), ';', '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="isms-register.csv"');

        return $response;
    }

    private function renderForm(IsmsRegisterEntry $entry, FormInterface $form): Response
    {
        $sections = [];
        foreach (IsmsRegisterEntryType::getSectionMap() as $key => $fields) {
            $present = array_values(array_filter($fields, static fn (string $f): bool => $form->has($f)));
            if ($present !== []) {
                $sections[$key] = ['label' => $this->translator->trans('register.section.' . $key, [], 'register'), 'fields' => $present];
            }
        }
        $links = array_values(array_filter(
            array_map(static fn (string $f): string => IsmsRegisterEntryType::LINK_PREFIX . $f, $this->linkTypes->fieldsFor($entry->getType())),
            static fn (string $f): bool => $form->has($f),
        ));
        if ($links !== []) {
            $sections['links'] = ['label' => $this->translator->trans('register.section.links', [], 'register'), 'fields' => $links];
        }

        return $this->render('register/form.html.twig', [
            'entry' => $entry,
            'type' => $entry->getType(),
            'form' => $form,
            'sections' => $sections,
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * External part of the communication plan: the schedule kept at the interested parties.
     *
     * @return list<\App\Entity\InterestedParty>
     */
    private function partySchedule(Tenant $tenant): array
    {
        $parties = array_values(array_filter(
            $this->parties->findBy(['tenant' => $tenant]),
            static fn ($p): bool => $p->getCommunicationFrequency() !== null || $p->getNextCommunication() !== null,
        ));
        usort($parties, static fn ($a, $b): int => ($a->getNextCommunication()?->getTimestamp() ?? PHP_INT_MAX)
            <=> ($b->getNextCommunication()?->getTimestamp() ?? PHP_INT_MAX));

        return $parties;
    }

    /** @return array<int, int> role entry id => open competence gaps */
    private function gapCountByRole(Tenant $tenant): array
    {
        $counts = [];
        foreach ($this->competences->gaps($tenant) as $gap) {
            $id = (int) $gap['role']->getId();
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        return $counts;
    }

    private function tenant(): Tenant
    {
        return $this->tenantContext->getCurrentTenant() ?? throw new NotFoundHttpException();
    }

    private function assertOwnTenant(IsmsRegisterEntry $entry): void
    {
        if ($entry->getTenant()?->getId() !== $this->tenant()->getId()) {
            throw new NotFoundHttpException();
        }
    }
}
