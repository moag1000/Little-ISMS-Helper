<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Role;
use App\Form\UserType;
use App\Repository\UserRepository;
use App\Repository\RoleRepository;
use App\Repository\AuditLogRepository;
use App\Repository\MfaTokenRepository;
use App\Security\Voter\UserVoter;
use App\Service\AuditLogger;
use App\Service\FileUploadSecurityService;
use App\Service\InitialAdminService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/users')]
class UserManagementController extends AbstractController
{
    public function __construct(
        private readonly FileUploadSecurityService $fileUploadService,
        private readonly SluggerInterface $slugger,
        private readonly LoggerInterface $logger,
        private readonly AuditLogger $auditLogger,
        private readonly InitialAdminService $initialAdminService,
        private readonly string $uploadsDirectory = 'uploads/users',
    ) {
    }

    #[Route('', name: 'user_management_index')]
    public function index(UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted(UserVoter::VIEW_ALL);

        $users = $userRepository->findAll();
        $statistics = $userRepository->getUserStatistics();

        // Identify the initial admin for UI display
        $initialAdmin = $this->initialAdminService->getInitialAdmin();
        $initialAdminId = $initialAdmin?->getId();

        return $this->render('user_management/index.html.twig', [
            'users' => $users,
            'statistics' => $statistics,
            'initial_admin_id' => $initialAdminId,
        ]);
    }

    #[Route('/new', name: 'user_management_new')]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        TranslatorInterface $translator
    ): Response {
        $this->denyAccessUnlessGranted(UserVoter::CREATE);

        $user = new User();
        $user->setAuthProvider('local');

        $form = $this->createForm(UserType::class, $user, [
            'is_edit' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Hash password if provided (and not empty)
            $plainPassword = $form->get('plainPassword')->getData();
            if (!empty($plainPassword) && trim($plainPassword) !== '') {
                $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
                $user->setPassword($hashedPassword);
            }

            // Handle avatar upload
            /** @var UploadedFile|null $avatarFile */
            $avatarFile = $form->get('avatarFile')->getData();
            if ($avatarFile) {
                $avatarPath = $this->handleAvatarUpload($avatarFile, $user);
                if ($avatarPath) {
                    $user->setProfilePicture($avatarPath);
                }
            }

            // Ensure ROLE_USER is always included
            $roles = $user->getRoles();
            if (!in_array('ROLE_USER', $roles)) {
                $roles[] = 'ROLE_USER';
                $user->setRoles($roles);
            }

            $entityManager->persist($user);
            $entityManager->flush();

            // Audit log
            $this->auditLogger->logCustom(
                'user_created',
                'User',
                $user->getId(),
                null,
                [
                    'email' => $user->getEmail(),
                    'first_name' => $user->getFirstName(),
                    'last_name' => $user->getLastName(),
                    'roles' => $user->getRoles(),
                    'is_active' => $user->isActive(),
                    'auth_provider' => $user->getAuthProvider(),
                ],
                sprintf('User "%s %s" (%s) created', $user->getFirstName(), $user->getLastName(), $user->getEmail())
            );

            $this->addFlash('success', $translator->trans('user.success.created'));

            return $this->redirectToRoute('user_management_index');
        }

        return $this->render('user_management/new.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route('/bulk-actions', name: 'user_management_bulk_actions', methods: ['POST'])]
    public function bulkActions(
        Request $request,
        UserRepository $userRepository,
        RoleRepository $roleRepository,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator
    ): Response {
        $this->denyAccessUnlessGranted(UserVoter::VIEW_ALL);

        $action = $request->request->get('action');
        $userIds = $request->request->all('user_ids') ?? [];

        if (empty($userIds)) {
            $this->addFlash('error', $translator->trans('user.error.no_users_selected'));
            return $this->redirectToRoute('user_management_index');
        }

        $users = $userRepository->findBy(['id' => $userIds]);
        $count = 0;
        $skippedCount = 0;
        $skippedReasons = [];

        foreach ($users as $user) {
            $skipped = false;
            $skipReason = null;

            switch ($action) {
                case 'activate':
                    if ($this->isGranted(UserVoter::EDIT, $user)) {
                        $user->setIsActive(true);
                        $user->setUpdatedAt(new \DateTimeImmutable());
                        $count++;
                    } else {
                        $skipped = true;
                        $skipReason = 'no_permission';
                    }
                    break;

                case 'deactivate':
                    if ($this->isGranted(UserVoter::EDIT, $user)) {
                        // Protect initial setup admin and current user
                        $isInitialAdmin = $this->initialAdminService->isInitialAdmin($user);
                        $isCurrentUser = $this->getUser() && $this->getUser()->getId() === $user->getId();

                        if ($isInitialAdmin) {
                            $skipped = true;
                            $skipReason = 'initial_admin';
                        } elseif ($isCurrentUser) {
                            $skipped = true;
                            $skipReason = 'current_user';
                        } else {
                            $user->setIsActive(false);
                            $user->setUpdatedAt(new \DateTimeImmutable());
                            $count++;
                        }
                    } else {
                        $skipped = true;
                        $skipReason = 'no_permission';
                    }
                    break;

                case 'assign_role':
                    $roleId = $request->request->get('role_id');
                    $role = $roleRepository->find($roleId);

                    if ($role && $this->isGranted(UserVoter::EDIT, $user)) {
                        if (!$user->getCustomRoles()->contains($role)) {
                            $user->addCustomRole($role);
                            $user->setUpdatedAt(new \DateTimeImmutable());
                            $count++;
                        } else {
                            $skipped = true;
                            $skipReason = 'already_has_role';
                        }
                    } else {
                        $skipped = true;
                        $skipReason = !$role ? 'role_not_found' : 'no_permission';
                    }
                    break;

                case 'delete':
                    if ($this->isGranted(UserVoter::DELETE, $user)) {
                        // Protect initial setup admin from deletion
                        $isInitialAdmin = $this->initialAdminService->isInitialAdmin($user);

                        if ($isInitialAdmin) {
                            $skipped = true;
                            $skipReason = 'initial_admin';
                        } else {
                            $entityManager->remove($user);
                            $count++;
                        }
                    } else {
                        $skipped = true;
                        $skipReason = 'no_permission';
                    }
                    break;
            }

            if ($skipped) {
                $skippedCount++;
                if ($skipReason) {
                    $skippedReasons[$skipReason] = ($skippedReasons[$skipReason] ?? 0) + 1;
                }
            }
        }

        $entityManager->flush();

        // Log bulk action
        $this->logger->info('Bulk action executed', [
            'action' => $action,
            'selected_users' => count($userIds),
            'processed' => $count,
            'skipped' => $skippedCount,
            'skipped_reasons' => $skippedReasons,
            'executor_id' => $this->getUser()?->getId(),
        ]);

        // Success message
        if ($count > 0) {
            $this->addFlash('success', $translator->trans('user.success.bulk_action_completed', [
                'count' => $count,
                'action' => $action,
            ]));
        }

        // Detailed feedback for skipped users
        if ($skippedCount > 0) {
            $reasonMessages = [];

            if (isset($skippedReasons['initial_admin'])) {
                $reasonMessages[] = $translator->trans('user.info.bulk_skipped_initial_admin', [
                    'count' => $skippedReasons['initial_admin'],
                ], 'messages');
            }

            if (isset($skippedReasons['current_user'])) {
                $reasonMessages[] = $translator->trans('user.info.bulk_skipped_current_user', [
                    'count' => $skippedReasons['current_user'],
                ], 'messages');
            }

            if (isset($skippedReasons['no_permission'])) {
                $reasonMessages[] = $translator->trans('user.info.bulk_skipped_no_permission', [
                    'count' => $skippedReasons['no_permission'],
                ], 'messages');
            }

            if (isset($skippedReasons['already_has_role'])) {
                $reasonMessages[] = $translator->trans('user.info.bulk_skipped_already_has_role', [
                    'count' => $skippedReasons['already_has_role'],
                ], 'messages');
            }

            foreach ($reasonMessages as $message) {
                $this->addFlash('info', $message);
            }
        }

        return $this->redirectToRoute('user_management_index');
    }


    #[Route('/export', name: 'user_management_export', methods: ['GET'])]
    public function export(
        UserRepository $userRepository
    ): StreamedResponse {
        $this->denyAccessUnlessGranted(UserVoter::VIEW_ALL);

        $users = $userRepository->findAll();

        $response = new StreamedResponse(function () use ($users) {
            $handle = fopen('php://output', 'w');

            // CSV Header
            fputcsv($handle, [
                'ID',
                'Email',
                'First Name',
                'Last Name',
                'Active',
                'MFA Enabled',
                'Tenant',
                'Roles',
                'Auth Provider',
                'Created At',
                'Last Login',
            ]);

            // CSV Rows
            foreach ($users as $user) {
                $roles = array_map(function ($role) {
                    return $role->getName();
                }, $user->getCustomRoles()->toArray());

                fputcsv($handle, [
                    $user->getId(),
                    $user->getEmail(),
                    $user->getFirstName(),
                    $user->getLastName(),
                    $user->isActive() ? 'Yes' : 'No',
                    'N/A', // MFA status - would need MfaTokenRepository to check
                    $user->getTenant() ? $user->getTenant()->getName() : '',
                    implode(', ', $roles),
                    $user->getAuthProvider(),
                    $user->getCreatedAt()?->format('Y-m-d H:i:s'),
                    $user->getLastLoginAt()?->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="users_export_' . date('Y-m-d_H-i-s') . '.csv"');

        return $response;
    }


    #[Route('/import', name: 'user_management_import', methods: ['GET', 'POST'])]
    public function import(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        RoleRepository $roleRepository,
        TranslatorInterface $translator
    ): Response {
        $this->denyAccessUnlessGranted(UserVoter::CREATE);

        if ($request->isMethod('POST')) {
            $file = $request->files->get('import_file');

            if (!$file) {
                $this->addFlash('error', $translator->trans('user.error.no_file_uploaded'));
                return $this->redirectToRoute('user_management_import');
            }

            $handle = fopen($file->getPathname(), 'r');
            $header = fgetcsv($handle); // Skip header

            $imported = 0;
            $errors = [];

            while (($row = fgetcsv($handle)) !== false) {
                try {
                    // Expected CSV format: email, first_name, last_name, password, is_active, roles
                    $email = $row[0] ?? null;
                    $firstName = $row[1] ?? null;
                    $lastName = $row[2] ?? null;
                    $password = $row[3] ?? null;
                    $isActive = ($row[4] ?? 'yes') === 'yes';
                    $roleNames = isset($row[5]) ? explode(',', $row[5]) : [];

                    if (!$email || !$firstName || !$lastName) {
                        $errors[] = "Skipped row: Missing required fields (email, first_name, last_name)";
                        continue;
                    }

                    // Check if user already exists
                    $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
                    if ($existingUser) {
                        $errors[] = "Skipped: User with email {$email} already exists";
                        continue;
                    }

                    $user = new User();
                    $user->setEmail($email);
                    $user->setFirstName($firstName);
                    $user->setLastName($lastName);
                    $user->setIsActive($isActive);
                    $user->setAuthProvider('local');

                    // Set password
                    if ($password) {
                        $hashedPassword = $passwordHasher->hashPassword($user, $password);
                        $user->setPassword($hashedPassword);
                    } else {
                        // Generate random password if not provided
                        $randomPassword = bin2hex(random_bytes(16));
                        $hashedPassword = $passwordHasher->hashPassword($user, $randomPassword);
                        $user->setPassword($hashedPassword);
                    }

                    // Assign roles
                    foreach ($roleNames as $roleName) {
                        $roleName = trim($roleName);
                        $role = $roleRepository->findOneBy(['name' => $roleName]);
                        if ($role) {
                            $user->addCustomRole($role);
                        }
                    }

                    $entityManager->persist($user);
                    $imported++;
                } catch (\Exception $e) {
                    $errors[] = "Error importing row: " . $e->getMessage();
                }
            }

            fclose($handle);

            $entityManager->flush();

            $this->addFlash('success', $translator->trans('user.success.imported', ['count' => $imported]));

            if (!empty($errors)) {
                foreach ($errors as $error) {
                    $this->addFlash('warning', $error);
                }
            }

            return $this->redirectToRoute('user_management_index');
        }

        return $this->render('user_management/import.html.twig');
    }


    #[Route('/{id}', name: 'user_management_show', requirements: ['id' => '\d+'])]
    public function show(User $user): Response
    {
        $this->denyAccessUnlessGranted(UserVoter::VIEW, $user);

        // Check if this is the initial setup admin
        $isInitialAdmin = $this->initialAdminService->isInitialAdmin($user);

        return $this->render('user_management/show.html.twig', [
            'user' => $user,
            'is_initial_admin' => $isInitialAdmin,
        ]);
    }

    #[Route('/{id}/edit', name: 'user_management_edit', requirements: ['id' => '\d+'])]
    public function edit(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        TranslatorInterface $translator
    ): Response {
        $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);

        // Check if this is the initial setup admin
        $isInitialAdmin = $this->initialAdminService->isInitialAdmin($user);

        // Capture old values for audit log
        $oldValues = [
            'email' => $user->getEmail(),
            'first_name' => $user->getFirstName(),
            'last_name' => $user->getLastName(),
            'roles' => $user->getRoles(),
            'is_active' => $user->isActive(),
            'department' => $user->getDepartment(),
            'job_title' => $user->getJobTitle(),
            'has_avatar' => $user->getProfilePicture() !== null,
        ];

        $oldAvatarPath = $user->getProfilePicture();
        $form = $this->createForm(UserType::class, $user, [
            'is_edit' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Check if user is editing themselves
            $isEditingSelf = $this->getUser() && $this->getUser()->getId() === $user->getId();

            // Protect initial setup admin from being deactivated
            if ($isInitialAdmin && !$user->isActive()) {
                $user->setIsActive(true);
                $this->addFlash('error', $translator->trans('user.error.cannot_deactivate_initial_admin', [], 'messages'));
            }

            // Protect initial setup admin from having ROLE_ADMIN removed
            if ($isInitialAdmin) {
                $newRoles = $user->getRoles();
                if (!in_array('ROLE_ADMIN', $newRoles) && !in_array('ROLE_SUPER_ADMIN', $newRoles)) {
                    // Force ROLE_ADMIN back
                    $storedRoles = $user->getStoredRoles();
                    if (!in_array('ROLE_ADMIN', $storedRoles)) {
                        $storedRoles[] = 'ROLE_ADMIN';
                        $user->setRoles($storedRoles);
                    }

                    $this->addFlash('error', $translator->trans('user.error.cannot_remove_admin_role_from_initial_admin', [], 'messages'));

                    // Log security-relevant attempt
                    $this->logger->warning('Attempt to remove ROLE_ADMIN from initial setup admin', [
                        'target_user_id' => $user->getId(),
                        'target_user_email' => $user->getEmail(),
                        'current_user_id' => $this->getUser()?->getId(),
                        'current_user_email' => $this->getUser()?->getUserIdentifier(),
                    ]);
                }
            }

            // Update password only if provided (and not empty)
            $plainPassword = $form->get('plainPassword')->getData();
            if (!empty($plainPassword) && trim($plainPassword) !== '') {
                $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
                $user->setPassword($hashedPassword);

                // Warn user if they changed their own password
                if ($isEditingSelf) {
                    $this->addFlash('warning', $translator->trans('user.warning.own_password_changed'));
                }
            }

            // Handle avatar upload
            /** @var UploadedFile|null $avatarFile */
            $avatarFile = $form->get('avatarFile')->getData();
            if ($avatarFile) {
                // Delete old avatar if exists
                if ($oldAvatarPath) {
                    $this->deleteOldAvatar($oldAvatarPath);
                }

                $avatarPath = $this->handleAvatarUpload($avatarFile, $user);
                if ($avatarPath) {
                    $user->setProfilePicture($avatarPath);
                }
            }

            // Ensure ROLE_USER is always included
            $roles = $user->getRoles();
            if (!in_array('ROLE_USER', $roles)) {
                $roles[] = 'ROLE_USER';
                $user->setRoles($roles);
            }

            $user->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();

            // Audit log with before/after values
            $newValues = [
                'email' => $user->getEmail(),
                'first_name' => $user->getFirstName(),
                'last_name' => $user->getLastName(),
                'roles' => $user->getRoles(),
                'is_active' => $user->isActive(),
                'department' => $user->getDepartment(),
                'job_title' => $user->getJobTitle(),
                'has_avatar' => $user->getProfilePicture() !== null,
            ];

            $this->auditLogger->logCustom(
                'user_updated',
                'User',
                $user->getId(),
                $oldValues,
                $newValues,
                sprintf('User "%s %s" (%s) updated', $user->getFirstName(), $user->getLastName(), $user->getEmail())
            );

            // Warn if user edited critical properties of their own account
            if ($isEditingSelf) {
                $criticalChanges = false;

                // Check for email change
                if ($oldValues['email'] !== $newValues['email']) {
                    $criticalChanges = true;
                }

                // Check for role changes
                if ($oldValues['roles'] !== $newValues['roles']) {
                    $criticalChanges = true;
                }

                // Check for account deactivation
                if ($oldValues['is_active'] && !$newValues['is_active']) {
                    $criticalChanges = true;
                }

                if ($criticalChanges || $plainPassword) {
                    $this->addFlash('warning', $translator->trans('user.warning.session_will_be_invalidated'));
                }
            }

            $this->addFlash('success', $translator->trans('user.success.updated'));

            return $this->redirectToRoute('user_management_show', ['id' => $user->getId()]);
        }

        return $this->render('user_management/edit.html.twig', [
            'user' => $user,
            'form' => $form,
            'is_initial_admin' => $isInitialAdmin,
        ]);
    }

    #[Route('/{id}/delete', name: 'user_management_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator
    ): Response {
        $this->denyAccessUnlessGranted(UserVoter::DELETE, $user);

        // Check if this is the initial setup admin
        $isInitialAdmin = $this->initialAdminService->isInitialAdmin($user);

        if ($isInitialAdmin) {
            // Log security-relevant attempt
            $this->logger->warning('Attempt to delete initial setup admin blocked', [
                'target_user_id' => $user->getId(),
                'target_user_email' => $user->getEmail(),
                'current_user_id' => $this->getUser()?->getId(),
                'current_user_email' => $this->getUser()?->getUserIdentifier(),
            ]);

            $this->addFlash('error', $translator->trans('user.error.cannot_delete_initial_admin', [], 'messages'));
            return $this->redirectToRoute('user_management_show', ['id' => $user->getId()]);
        }

        if ($this->isCsrfTokenValid('delete' . $user->getId(), $request->request->get('_token'))) {
            // Capture user data for audit log before deletion
            $userId = $user->getId();
            $userEmail = $user->getEmail();
            $userName = $user->getFirstName() . ' ' . $user->getLastName();

            $oldValues = [
                'email' => $userEmail,
                'first_name' => $user->getFirstName(),
                'last_name' => $user->getLastName(),
                'roles' => $user->getRoles(),
            ];

            $entityManager->remove($user);
            $entityManager->flush();

            // Audit log
            $this->auditLogger->logCustom(
                'user_deleted',
                'User',
                $userId,
                $oldValues,
                null,
                sprintf('User "%s" (%s) deleted', $userName, $userEmail)
            );

            $this->addFlash('success', $translator->trans('user.success.deleted'));
        }

        return $this->redirectToRoute('user_management_index');
    }

    #[Route('/{id}/toggle-active', name: 'user_management_toggle_active', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleActive(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator
    ): Response {
        $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);

        if ($this->isCsrfTokenValid('toggle-active' . $user->getId(), $request->request->get('_token'))) {
            // Check if this is the initial setup admin
            $isInitialAdmin = $this->initialAdminService->isInitialAdmin($user);

            if ($isInitialAdmin && $user->isActive()) {
                // Log security-relevant attempt
                $this->logger->warning('Attempt to deactivate initial setup admin blocked', [
                    'target_user_id' => $user->getId(),
                    'target_user_email' => $user->getEmail(),
                    'current_user_id' => $this->getUser()?->getId(),
                    'current_user_email' => $this->getUser()?->getUserIdentifier(),
                ]);

                $this->addFlash('error', $translator->trans('user.error.cannot_deactivate_initial_admin', [], 'messages'));
                return $this->redirectToRoute('user_management_show', ['id' => $user->getId()]);
            }

            $previousStatus = $user->isActive();
            $user->setIsActive(!$user->isActive());
            $user->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();

            // Audit log
            $this->auditLogger->logCustom(
                'user_status_toggled',
                'User',
                $user->getId(),
                ['is_active' => $previousStatus],
                ['is_active' => $user->isActive()],
                sprintf('User "%s %s" (%s) %s',
                    $user->getFirstName(),
                    $user->getLastName(),
                    $user->getEmail(),
                    $user->isActive() ? 'activated' : 'deactivated'
                )
            );

            $this->addFlash('success', $user->isActive() ? $translator->trans('user.success.activated') : $translator->trans('user.success.deactivated'));
        }

        return $this->redirectToRoute('user_management_show', ['id' => $user->getId()]);
    }

    #[Route('/{id}/activity', name: 'user_management_activity', requirements: ['id' => '\d+'])]
    public function activity(
        User $user,
        AuditLogRepository $auditLogRepository,
        Request $request
    ): Response {
        $this->denyAccessUnlessGranted(UserVoter::VIEW, $user);

        $limit = (int) $request->query->get('limit', 100);
        $activities = $auditLogRepository->findByUser($user->getEmail(), $limit);

        // Group activities by date
        $activitiesByDate = [];
        foreach ($activities as $activity) {
            $date = $activity->getCreatedAt()->format('Y-m-d');
            if (!isset($activitiesByDate[$date])) {
                $activitiesByDate[$date] = [];
            }
            $activitiesByDate[$date][] = $activity;
        }

        // Calculate statistics
        $actionCounts = [];
        foreach ($activities as $activity) {
            $action = $activity->getAction();
            if (!isset($actionCounts[$action])) {
                $actionCounts[$action] = 0;
            }
            $actionCounts[$action]++;
        }

        return $this->render('user_management/activity.html.twig', [
            'user' => $user,
            'activities' => $activities,
            'activities_by_date' => $activitiesByDate,
            'action_counts' => $actionCounts,
            'total_activities' => count($activities),
        ]);
    }

    #[Route('/{id}/mfa', name: 'user_management_mfa', requirements: ['id' => '\d+'])]
    public function mfa(
        User $user,
        MfaTokenRepository $mfaTokenRepository
    ): Response {
        $this->denyAccessUnlessGranted(UserVoter::VIEW, $user);

        $mfaTokens = $mfaTokenRepository->findBy(['user' => $user], ['createdAt' => 'DESC']);

        // Calculate MFA statistics
        $activeTokens = array_filter($mfaTokens, fn($token) => $token->isActive());
        $tokensByType = [];
        foreach ($mfaTokens as $token) {
            $type = $token->getTokenType();
            if (!isset($tokensByType[$type])) {
                $tokensByType[$type] = 0;
            }
            $tokensByType[$type]++;
        }

        return $this->render('user_management/mfa.html.twig', [
            'user' => $user,
            'mfa_tokens' => $mfaTokens,
            'active_tokens_count' => count($activeTokens),
            'tokens_by_type' => $tokensByType,
            'mfa_enabled' => count($activeTokens) > 0,
        ]);
    }

    #[Route('/{id}/mfa/{tokenId}/reset', name: 'user_management_mfa_reset', requirements: ['id' => '\d+', 'tokenId' => '\d+'], methods: ['POST'])]
    public function mfaReset(
        User $user,
        int $tokenId,
        Request $request,
        MfaTokenRepository $mfaTokenRepository,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $token = $mfaTokenRepository->find($tokenId);

        if (!$token || $token->getUser()->getId() !== $user->getId()) {
            $this->addFlash('error', $translator->trans('mfa.error.token_not_found'));
            return $this->redirectToRoute('user_management_mfa', ['id' => $user->getId()]);
        }

        if ($this->isCsrfTokenValid('mfa_reset_' . $tokenId, $request->request->get('_token'))) {
            $entityManager->remove($token);
            $entityManager->flush();

            $this->addFlash('success', $translator->trans('mfa.success.token_reset'));
        }

        return $this->redirectToRoute('user_management_mfa', ['id' => $user->getId()]);
    }

    #[Route('/{id}/impersonate', name: 'user_management_impersonate', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function impersonate(User $user): Response
    {
        // Redirect to the homepage with the impersonation parameter
        // Using direct URL with _switch_user parameter for Symfony's user impersonation
        return $this->redirect('/?_switch_user=' . urlencode($user->getEmail()));
    }

    /**
     * Handle avatar upload with security validation
     */
    private function handleAvatarUpload(UploadedFile $file, User $user): ?string
    {
        try {
            // Security validation using FileUploadSecurityService
            $validation = $this->fileUploadService->validateUpload($file);

            if (!$validation['valid']) {
                $this->addFlash('warning', 'Avatar upload failed: ' . $validation['error']);
                $this->logger->warning('Avatar upload validation failed', [
                    'user_email' => $user->getEmail(),
                    'error' => $validation['error'],
                ]);
                return null;
            }

            // Generate safe filename
            $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $this->slugger->slug($originalFilename);
            $newFilename = sprintf(
                'user-%d-%s.%s',
                $user->getId() ?: uniqid(),
                uniqid(),
                $file->guessExtension()
            );

            // Move file to uploads directory
            $uploadsPath = $this->getParameter('kernel.project_dir') . '/public/' . $this->uploadsDirectory;

            // Create directory if it doesn't exist
            if (!is_dir($uploadsPath)) {
                mkdir($uploadsPath, 0755, true);
            }

            $file->move($uploadsPath, $newFilename);

            $this->logger->info('Avatar uploaded successfully', [
                'user_email' => $user->getEmail(),
                'filename' => $newFilename,
            ]);

            return $this->uploadsDirectory . '/' . $newFilename;

        } catch (\Exception $e) {
            $this->logger->error('Avatar upload failed', [
                'user_email' => $user->getEmail(),
                'error' => $e->getMessage(),
            ]);
            $this->addFlash('warning', 'Avatar upload failed. Please try again.');
            return null;
        }
    }

    /**
     * Delete old avatar file
     */
    private function deleteOldAvatar(string $avatarPath): void
    {
        try {
            $fullPath = $this->getParameter('kernel.project_dir') . '/public/' . $avatarPath;
            if (file_exists($fullPath)) {
                unlink($fullPath);
                $this->logger->info('Old avatar deleted', ['path' => $avatarPath]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete old avatar', [
                'path' => $avatarPath,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
