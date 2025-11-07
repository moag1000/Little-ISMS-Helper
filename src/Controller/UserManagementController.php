<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Role;
use App\Repository\UserRepository;
use App\Repository\RoleRepository;
use App\Security\Voter\UserVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class UserManagementController extends AbstractController
{
    #[Route('', name: 'user_management_index')]
    public function index(UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted(UserVoter::VIEW_ALL);

        $users = $userRepository->findAll();
        $statistics = $userRepository->getUserStatistics();

        return $this->render('user_management/index.html.twig', [
            'users' => $users,
            'statistics' => $statistics,
        ]);
    }

    #[Route('/new', name: 'user_management_new')]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        RoleRepository $roleRepository
    ): Response {
        $this->denyAccessUnlessGranted(UserVoter::CREATE);

        $user = new User();
        $availableRoles = $roleRepository->findAll();

        if ($request->isMethod('POST')) {
            $user->setEmail($request->request->get('email'));
            $user->setFirstName($request->request->get('firstName'));
            $user->setLastName($request->request->get('lastName'));
            $user->setDepartment($request->request->get('department'));
            $user->setJobTitle($request->request->get('jobTitle'));
            $user->setPhoneNumber($request->request->get('phoneNumber'));
            $user->setAuthProvider('local');

            // Set password if provided
            $password = $request->request->get('password');
            if ($password) {
                $hashedPassword = $passwordHasher->hashPassword($user, $password);
                $user->setPassword($hashedPassword);
            }

            // Set roles
            $selectedRoles = $request->request->all('roles') ?? [];
            $user->setRoles($selectedRoles);

            // Set custom roles
            $customRoleIds = $request->request->all('customRoles') ?? [];
            foreach ($customRoleIds as $roleId) {
                $role = $roleRepository->find($roleId);
                if ($role) {
                    $user->addCustomRole($role);
                }
            }

            $user->setIsActive($request->request->get('isActive', false) === '1');
            $user->setIsVerified($request->request->get('isVerified', false) === '1');

            $entityManager->persist($user);
            $entityManager->flush();

            $this->addFlash('success', 'Benutzer erfolgreich erstellt.');

            return $this->redirectToRoute('user_management_index');
        }

        return $this->render('user_management/new.html.twig', [
            'user' => $user,
            'availableRoles' => $availableRoles,
        ]);
    }

    #[Route('/{id}', name: 'user_management_show', requirements: ['id' => '\d+'])]
    public function show(User $user): Response
    {
        $this->denyAccessUnlessGranted(UserVoter::VIEW, $user);

        return $this->render('user_management/show.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{id}/edit', name: 'user_management_edit', requirements: ['id' => '\d+'])]
    public function edit(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        RoleRepository $roleRepository
    ): Response {
        $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);

        $availableRoles = $roleRepository->findAll();

        if ($request->isMethod('POST')) {
            $user->setEmail($request->request->get('email'));
            $user->setFirstName($request->request->get('firstName'));
            $user->setLastName($request->request->get('lastName'));
            $user->setDepartment($request->request->get('department'));
            $user->setJobTitle($request->request->get('jobTitle'));
            $user->setPhoneNumber($request->request->get('phoneNumber'));

            // Update password only if provided
            $password = $request->request->get('password');
            if ($password) {
                $hashedPassword = $passwordHasher->hashPassword($user, $password);
                $user->setPassword($hashedPassword);
            }

            // Update roles
            $selectedRoles = $request->request->all('roles') ?? [];
            $user->setRoles($selectedRoles);

            // Update custom roles
            $user->getCustomRoles()->clear();
            $customRoleIds = $request->request->all('customRoles') ?? [];
            foreach ($customRoleIds as $roleId) {
                $role = $roleRepository->find($roleId);
                if ($role) {
                    $user->addCustomRole($role);
                }
            }

            $user->setIsActive($request->request->get('isActive', false) === '1');
            $user->setIsVerified($request->request->get('isVerified', false) === '1');
            $user->setUpdatedAt(new \DateTimeImmutable());

            $entityManager->flush();

            $this->addFlash('success', 'Benutzer erfolgreich aktualisiert.');

            return $this->redirectToRoute('user_management_show', ['id' => $user->getId()]);
        }

        return $this->render('user_management/edit.html.twig', [
            'user' => $user,
            'availableRoles' => $availableRoles,
        ]);
    }

    #[Route('/{id}/delete', name: 'user_management_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $this->denyAccessUnlessGranted(UserVoter::DELETE, $user);

        if ($this->isCsrfTokenValid('delete' . $user->getId(), $request->request->get('_token'))) {
            $entityManager->remove($user);
            $entityManager->flush();

            $this->addFlash('success', 'Benutzer erfolgreich gelöscht.');
        }

        return $this->redirectToRoute('user_management_index');
    }

    #[Route('/{id}/toggle-active', name: 'user_management_toggle_active', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleActive(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);

        if ($this->isCsrfTokenValid('toggle-active' . $user->getId(), $request->request->get('_token'))) {
            $user->setIsActive(!$user->isActive());
            $user->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('success', $user->isActive() ? 'Benutzer aktiviert.' : 'Benutzer deaktiviert.');
        }

        return $this->redirectToRoute('user_management_show', ['id' => $user->getId()]);
    }
}
