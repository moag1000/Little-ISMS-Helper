<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Service\AuditLogger;
use App\Service\FileUploadSecurityService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly FileUploadSecurityService $fileUploadSecurityService,
        private readonly SluggerInterface $slugger,
        private readonly LoggerInterface $logger,
        private readonly AuditLogger $auditLogger,
        private readonly string $uploadsDirectory = 'uploads/users',
    ) {
    }

    #[Route('/profile', name: 'app_profile')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('profile/index.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/profile/edit', name: 'app_profile_edit')]
    public function edit(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $userPasswordHasher,
        TranslatorInterface $translator
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Capture old values for audit log
        $oldValues = [
            'first_name' => $user->getFirstName(),
            'last_name' => $user->getLastName(),
            'department' => $user->getDepartment(),
            'job_title' => $user->getJobTitle(),
            'phone_number' => $user->getPhoneNumber(),
            'language' => $user->getLanguage(),
            'timezone' => $user->getTimezone(),
            'has_avatar' => $user->getProfilePicture() !== null,
        ];

        $oldAvatarPath = $user->getProfilePicture();

        $form = $this->createForm(UserType::class, $user, [
            'is_edit' => true,
            'is_profile_edit' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Handle password change
            $plainPassword = $form->get('plainPassword')->getData();
            if (!empty($plainPassword) && trim((string) $plainPassword) !== '') {
                $hashedPassword = $userPasswordHasher->hashPassword($user, $plainPassword);
                $user->setPassword($hashedPassword);

                $this->addFlash('success', $translator->trans('profile.success.password_changed', [], 'user'));
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

            $user->setUpdatedAt(new DateTimeImmutable());
            $entityManager->flush();

            // Audit log with before/after values
            $newValues = [
                'first_name' => $user->getFirstName(),
                'last_name' => $user->getLastName(),
                'department' => $user->getDepartment(),
                'job_title' => $user->getJobTitle(),
                'phone_number' => $user->getPhoneNumber(),
                'language' => $user->getLanguage(),
                'timezone' => $user->getTimezone(),
                'has_avatar' => $user->getProfilePicture() !== null,
            ];

            $this->auditLogger->logCustom(
                'profile_updated',
                'User',
                $user->getId(),
                $oldValues,
                $newValues,
                sprintf('User "%s %s" updated their profile', $user->getFirstName(), $user->getLastName())
            );

            $this->addFlash('success', $translator->trans('profile.success.updated', [], 'user'));

            return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
        }

        return $this->render('profile/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route('/profile/avatar/delete', name: 'app_profile_avatar_delete', methods: ['POST'])]
    public function deleteAvatar(
        Request $request,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isCsrfTokenValid('delete_avatar' . $user->getId(), $request->request->get('_token'))) {
            $oldAvatarPath = $user->getProfilePicture();

            if ($oldAvatarPath) {
                $this->deleteOldAvatar($oldAvatarPath);
                $user->setProfilePicture(null);
                $user->setUpdatedAt(new DateTimeImmutable());
                $entityManager->flush();

                // Audit log
                $this->auditLogger->logCustom(
                    'profile_avatar_deleted',
                    'User',
                    $user->getId(),
                    ['avatar_path' => $oldAvatarPath],
                    ['avatar_path' => null],
                    sprintf('User "%s %s" deleted their profile avatar', $user->getFirstName(), $user->getLastName())
                );

                $this->addFlash('success', $translator->trans('profile.success.avatar_deleted', [], 'user'));
            }
        }

        return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
    }

    /**
     * Handle avatar upload with security validation
     */
    private function handleAvatarUpload(UploadedFile $uploadedFile, User $user): ?string
    {
        try {
            // Security validation using FileUploadSecurityService
            $validation = $this->fileUploadSecurityService->validateUpload($uploadedFile);

            if (!$validation['valid']) {
                $this->addFlash('warning', 'Avatar upload failed: ' . $validation['error']);
                $this->logger->warning('Avatar upload validation failed', [
                    'user_email' => $user->getEmail(),
                    'error' => $validation['error'],
                ]);
                return null;
            }

            // Generate safe filename
            $originalFilename = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $this->slugger->slug($originalFilename);
            $newFilename = sprintf(
                'user-%d-%s.%s',
                $user->getId() ?: uniqid(),
                uniqid(),
                $uploadedFile->guessExtension()
            );

            // Move file to uploads directory
            $uploadsPath = $this->getParameter('kernel.project_dir') . '/public/' . $this->uploadsDirectory;

            // Create directory if it doesn't exist
            if (!is_dir($uploadsPath)) {
                mkdir($uploadsPath, 0755, true);
            }

            $uploadedFile->move($uploadsPath, $newFilename);

            $this->logger->info('Avatar uploaded successfully', [
                'user_email' => $user->getEmail(),
                'filename' => $newFilename,
            ]);

            return $this->uploadsDirectory . '/' . $newFilename;

        } catch (Exception $e) {
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
        } catch (Exception $e) {
            $this->logger->error('Failed to delete old avatar', [
                'path' => $avatarPath,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
