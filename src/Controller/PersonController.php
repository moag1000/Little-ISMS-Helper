<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresModule;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Entity\Person;
use App\Entity\User;
use App\Form\PersonType;
use App\Repository\PersonRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Util\ListPage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[RequiresModule('persons')]
class PersonController extends AbstractController
{
    public function __construct(
        private readonly PersonRepository $personRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly AuditLogger $auditLogger,
    ) {}
    #[Route('/person', name: 'app_person_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        $tenant = $this->security->getUser()?->getTenant();
        $persons = $tenant ? $this->personRepository->findBy(['tenant' => $tenant]) : [];

        $type = (string) $request->query->get('type', '');
        if ($type !== '') {
            $persons = array_values(array_filter($persons, static fn(Person $p): bool => $p->getPersonType() === $type));
        }

        return $this->render('person/index.html.twig', [
            'page' => ListPage::fromRequest(
                $persons,
                $request,
                static fn(Person $p): array => [$p->getFullName(), $p->getJobTitle(), $p->getCompany(), $p->getEmail(), $p->getDepartment(), $p->getBadgeId()],
            ),
            'employee_count' => count(array_filter($persons, static fn(Person $p): bool => $p->getPersonType() === 'employee')),
            'contractor_count' => count(array_filter($persons, static fn(Person $p): bool => $p->getPersonType() === 'contractor')),
            'badge_count' => count(array_filter($persons, static fn(Person $p): bool => (bool) $p->getBadgeId())),
        ]);
    }
    #[Route('/person/new', name: 'app_person_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $person = new Person();

        $currentUser = $this->security->getUser();
        $tenant = $currentUser instanceof UserInterface ? $currentUser->getTenant() : null;

        if ($tenant) {
            $person->setTenant($tenant);
        }

        $prefilledFromUser = false;
        $fromUserId = $request->query->get('from_user');
        if ($fromUserId !== null && ctype_digit((string) $fromUserId)) {
            $sourceUser = $this->userRepository->find((int) $fromUserId);
            if ($sourceUser instanceof User
                && $tenant !== null
                && $sourceUser->getTenant() === $tenant
            ) {
                $this->prefillPersonFromUser($person, $sourceUser);
                $prefilledFromUser = true;
            }
        }

        $form = $this->createForm(PersonType::class, $person);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($person);
            $this->entityManager->flush();
            $this->logAccountLink($person, null);

            $this->addFlash('success', $this->translator->trans('person.success.created', [], 'messages'));
            return $this->redirectToRoute('app_person_show', ['id' => $person->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('person/new.html.twig', [
            'person' => $person,
            'form' => $form,
            'available_users' => $this->personRepository->findUsersAvailableToLink($tenant),
            'prefilled_from_user' => $prefilledFromUser,
        ], new Response(status: $status));
    }

    private function prefillPersonFromUser(Person $person, User $sourceUser): void
    {
        if ($this->isGranted('ROLE_MANAGER')) {
            // S20: the link carries the account's duties — only a manager sets it (SelfAssignmentGuard).
            $person->setLinkedUser($sourceUser);
        }
        $person->setPersonType('employee');

        $fullName = trim((string) $sourceUser->getFullName());
        if ($fullName !== '') {
            $person->setFullName($fullName);
        }
        if ($sourceUser->getEmail()) {
            $person->setEmail($sourceUser->getEmail());
        }
        if ($sourceUser->getPhoneNumber()) {
            $person->setPhone($sourceUser->getPhoneNumber());
        }
        if ($sourceUser->getDepartment()) {
            $person->setDepartment($sourceUser->getDepartment());
        }
        if ($sourceUser->getJobTitle()) {
            $person->setJobTitle($sourceUser->getJobTitle());
        }
    }
    #[Route('/person/{id}', name: 'app_person_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(Person $person): Response
    {
        $accessLogs = $person->getAccessLogs();

        return $this->render('person/show.html.twig', [
            'person' => $person,
            'access_logs' => $accessLogs,
        ]);
    }
    #[Route('/person/{id}/edit', name: 'app_person_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Person $person): Response
    {
        $linkedBefore = $person->getLinkedUser()?->getId();
        $form = $this->createForm(PersonType::class, $person);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->logAccountLink($person, $linkedBefore);

            $this->addFlash('success', $this->translator->trans('person.success.updated', [], 'messages'));
            return $this->redirectToRoute('app_person_show', ['id' => $person->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('person/edit.html.twig', [
            'person' => $person,
            'form' => $form,
        ], new Response(status: $status));
    }
    /** S20: the account a person acts through carries its duties — every change is in the audit log. */
    private function logAccountLink(Person $person, ?int $before): void
    {
        $after = $person->getLinkedUser()?->getId();
        if ($after === $before) {
            return;
        }
        $this->auditLogger->logUpdate(
            'Person',
            $person->getId(),
            ['linkedUser' => $before],
            ['linkedUser' => $after],
            'Person linked to user account changed',
        );
    }

    #[Route('/person/{id}/delete', name: 'app_person_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Person $person): Response
    {
        if ($this->isCsrfTokenValid('delete'.$person->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($person);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('person.success.deleted', [], 'messages'));
        }

        return $this->redirectToRoute('app_person_index');
    }
}
