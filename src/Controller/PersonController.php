<?php

namespace App\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use App\Entity\Person;
use App\Form\PersonType;
use App\Repository\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class PersonController extends AbstractController
{
    public function __construct(
        private readonly PersonRepository $personRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security
    ) {}
    #[Route('/person/', name: 'app_person_index')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $persons = $this->personRepository->findAll();
        $statistics = $this->personRepository->getStatistics();

        return $this->render('person/index.html.twig', [
            'persons' => $persons,
            'statistics' => $statistics,
        ]);
    }
    #[Route('/person/new', name: 'app_person_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $person = new Person();

        // Set tenant from current user
        $user = $this->security->getUser();
        if ($user instanceof UserInterface && $user->getTenant()) {
            $person->setTenant($user->getTenant());
        }

        $form = $this->createForm(PersonType::class, $person);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($person);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('person.success.created'));
            return $this->redirectToRoute('app_person_show', ['id' => $person->getId()]);
        }

        return $this->render('person/new.html.twig', [
            'person' => $person,
            'form' => $form,
        ]);
    }
    #[Route('/person/{id}', name: 'app_person_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(Person $person): Response
    {
        $accessLogs = $person->getAccessLogs();

        return $this->render('person/show.html.twig', [
            'person' => $person,
            'access_logs' => $accessLogs,
        ]);
    }
    #[Route('/person/{id}/edit', name: 'app_person_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Person $person): Response
    {
        $form = $this->createForm(PersonType::class, $person);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('person.success.updated'));
            return $this->redirectToRoute('app_person_show', ['id' => $person->getId()]);
        }

        return $this->render('person/edit.html.twig', [
            'person' => $person,
            'form' => $form,
        ]);
    }
    #[Route('/person/{id}/delete', name: 'app_person_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Person $person): Response
    {
        if ($this->isCsrfTokenValid('delete'.$person->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($person);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('person.success.deleted'));
        }

        return $this->redirectToRoute('app_person_index');
    }
}
