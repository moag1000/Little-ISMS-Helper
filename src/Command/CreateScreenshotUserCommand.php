<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-screenshot-user',
    description: 'Idempotent screenshot/demo user (dev/test only): ROLE_SUPER_ADMIN + ROLE_MANAGER + ROLE_AUDITOR + ROLE_DPO. Used by scripts/screenshots/capture.mjs.',
)]
class CreateScreenshotUserCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly TenantRepository $tenantRepository,
        private readonly UserPasswordHasherInterface $userPasswordHasher,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $kernelEnvironment,
    ) {
    }

    public function __invoke(
        #[Option(name: 'email', description: 'Email for the screenshot user')]
        string $email = 'screenshots@local.test',
        #[Option(name: 'password', description: 'Password for the screenshot user')]
        string $password = 'Screenshots-Aurora-2026!',
        #[Option(name: 'tenant-code', description: 'Tenant code to assign / create')]
        string $tenantCode = 'screenshots',
        #[Option(name: 'tenant-name', description: 'Tenant name when newly created')]
        string $tenantName = 'Screenshots Demo Tenant',
        #[Option(name: 'force', description: 'Allow execution outside dev/test environments')]
        bool $force = false,
        ?SymfonyStyle $symfonyStyle = null,
    ): int {
        $symfonyStyle ??= new SymfonyStyle(new \Symfony\Component\Console\Input\ArgvInput(), new \Symfony\Component\Console\Output\ConsoleOutput());

        if (!$force && !\in_array($this->kernelEnvironment, ['dev', 'test'], true)) {
            $symfonyStyle->error(sprintf('Refusing to run in environment "%s". Pass --force to override.', $this->kernelEnvironment));
            return Command::FAILURE;
        }

        $tenant = $this->resolveTenant($tenantCode, $tenantName);
        $user = $this->userRepository->findOneBy(['email' => $email]);
        $isNew = $user === null;

        if ($isNew) {
            $user = new User();
            $user->setEmail($email);
        }

        $user->setFirstName('Screenshot');
        $user->setLastName('Bot');
        $user->setRoles(['ROLE_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_MANAGER', 'ROLE_AUDITOR', 'ROLE_USER', 'ROLE_DPO']);
        $user->setAuthProvider('local');
        $user->setIsActive(true);
        $user->setIsVerified(true);
        $user->setTenant($tenant);
        $user->setPassword($this->userPasswordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $symfonyStyle->success(sprintf(
            '%s screenshot user: %s (tenant: %s)',
            $isNew ? 'Created' : 'Updated',
            $email,
            $tenant->getCode(),
        ));
        $symfonyStyle->writeln('');
        $symfonyStyle->writeln('Run capture:');
        $symfonyStyle->writeln(sprintf("  SCREENSHOT_USER='%s' SCREENSHOT_PASS='%s' npm run screenshots", $email, $password));

        return Command::SUCCESS;
    }

    private function resolveTenant(string $code, string $name): Tenant
    {
        $tenant = $this->tenantRepository->findOneBy(['code' => $code]);
        if ($tenant !== null) {
            return $tenant;
        }

        $tenant = new Tenant();
        $tenant->setCode($code);
        $tenant->setName($name);
        $tenant->setIsActive(true);
        if (method_exists($tenant, 'setCreatedAt')) {
            $tenant->setCreatedAt(new DateTimeImmutable());
        }
        $this->entityManager->persist($tenant);
        $this->entityManager->flush();
        return $tenant;
    }
}
