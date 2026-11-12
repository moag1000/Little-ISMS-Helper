<?php

declare(strict_types=1);

namespace App\Controller\GateFixture;

use App\Entity\GateFixture\ContactRecord;
use App\Entity\GateFixture\PersonRecord;
use App\Security\PersonalDataVisibility;
use App\Security\PrivacyExportAccess;
use App\Security\Voter\PersonalDataRecordVoter;
use App\Security\Voter\RecordResponsibleVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Gate 104 fixture: every `expect:` line is a mutation the rule must report. */
#[Route('/person-record')]
#[IsGranted('ROLE_USER')]
final class PersonRecordController extends AbstractController
{
    #[Route('', name: 'fixture_person_index', methods: ['GET'])]
    public function index(): Response // expect: gate104.read
    {
        return new Response();
    }

    #[Route('/filtered', name: 'fixture_person_filtered', methods: ['GET'])]
    public function filtered(): Response
    {
        return new Response((string) count($this->visible([])));
    }

    #[Route('/inline', name: 'fixture_person_inline', methods: ['GET'])]
    public function inline(): Response
    {
        return new Response($this->isGranted(PersonalDataRecordVoter::VIEW, new PersonRecord()) ? '1' : '0');
    }

    #[Route('/{id}', name: 'fixture_person_show', methods: ['GET'])]
    public function show(PersonRecord $record): Response // expect: gate104.read
    {
        return new Response();
    }

    /** Filtering inside the detail page is no rule: the page itself must refuse. */
    #[Route('/{id}/peek', name: 'fixture_person_peek', methods: ['GET'])]
    public function peek(PersonRecord $record): Response // expect: gate104.read
    {
        return new Response($this->isGranted(PersonalDataRecordVoter::VIEW, $record) ? '1' : '0');
    }

    #[Route('/{id}/user', name: 'fixture_person_user', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function onlyUser(PersonRecord $record): Response // expect: gate104.read
    {
        return new Response();
    }

    #[Route('/{id}/auditor', name: 'fixture_person_auditor', methods: ['GET'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function auditor(?PersonRecord $record): Response // expect: gate104.read
    {
        return new Response();
    }

    #[Route('/{id}/voter', name: 'fixture_person_voter', methods: ['GET'])]
    #[IsGranted(PersonalDataRecordVoter::VIEW, subject: 'record')]
    public function voter(PersonRecord $record): Response
    {
        return new Response();
    }

    #[Route('/{id}/edit', name: 'fixture_person_edit', methods: ['GET', 'POST'])]
    #[IsGranted(new Expression(RecordResponsibleVoter::MANAGER_OR_RESPONSIBLE), subject: 'record')]
    public function edit(PersonRecord $record): Response
    {
        return new Response();
    }

    #[Route('/{id}/pdf', name: 'fixture_person_pdf', methods: ['GET'])]
    #[IsGranted(new Expression(PrivacyExportAccess::RULE))]
    public function pdf(PersonRecord $record): Response
    {
        return new Response();
    }

    #[Route('/{id}/manager', name: 'fixture_person_manager')]
    #[IsGranted('ROLE_MANAGER')]
    public function manager(PersonRecord $record): Response
    {
        return new Response();
    }

    #[Route('/{id}/any-method', name: 'fixture_person_any')]
    public function anyMethod(PersonRecord $record): Response // expect: gate104.read
    {
        return new Response();
    }

    #[Route('/{id}/delete', name: 'fixture_person_delete', methods: ['POST'])]
    public function delete(PersonRecord $record): Response
    {
        return new Response();
    }

    /** @personal-data-read-ok: fixture — the reviewed exception. */
    #[Route('/{id}/reviewed', name: 'fixture_person_reviewed', methods: ['GET'])]
    public function reviewed(PersonRecord $record): Response
    {
        return new Response();
    }

    /**
     * @param array<PersonRecord> $records
     * @return list<PersonRecord>
     */
    private function visible(array $records): array
    {
        return array_values(array_filter($records, fn (PersonRecord $r): bool => $this->isGranted(PersonalDataRecordVoter::VIEW, $r)));
    }
}

/** Only field-level personal data (contact fields): no record action, the rule has nothing to say. */
#[Route('/plain')]
final class PlainController extends AbstractController
{
    #[Route('', name: 'fixture_plain_index', methods: ['GET'])]
    public function index(): Response
    {
        return new Response();
    }

    #[Route('/contact/{id}', name: 'fixture_contact_show', methods: ['GET'])]
    public function contact(ContactRecord $record): Response
    {
        return new Response();
    }
}

/** Filtering through the shared visibility service counts — only on a property of that type. */
#[Route('/person-visible')]
final class VisiblePersonRecordController extends AbstractController
{
    public function __construct(
        private readonly PersonalDataVisibility $personalData,
        private readonly ?\ArrayObject $other = null,
    ) {
    }

    #[Route('', name: 'fixture_visible_index', methods: ['GET'])]
    public function index(): Response
    {
        return new Response((string) count($this->personalData->readable([])));
    }

    #[Route('/items', name: 'fixture_visible_items', methods: ['GET'])]
    public function items(): Response
    {
        return new Response((string) count($this->personalData->readableItems([], 'id', static fn (): array => [], static fn (object $r): int => 1)));
    }

    #[Route('/other', name: 'fixture_visible_other', methods: ['GET'])]
    public function other(): Response // expect: gate104.read
    {
        return new Response((string) $this->other?->readable([]));
    }

    #[Route('/{id}', name: 'fixture_visible_show', methods: ['GET'])]
    #[IsGranted(PersonalDataRecordVoter::VIEW, subject: 'record')]
    public function show(PersonRecord $record): Response
    {
        return new Response();
    }
}
