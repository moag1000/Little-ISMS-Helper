<?php

namespace App\Security;

use DateTimeImmutable;
use TheNetworg\OAuth2\Client\Provider\AzureResourceOwner;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class AzureOAuthAuthenticator extends OAuth2Authenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly RouterInterface $router
    ) {
    }

    public function supports(Request $request): ?bool
    {
        // Continue ONLY if the current route matches the check route
        return $request->attributes->get('_route') === 'oauth_azure_check';
    }

    public function authenticate(Request $request): Passport
    {
        $oAuth2Client = $this->clientRegistry->getClient('azure');
        $accessToken = $this->fetchAccessToken($oAuth2Client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function() use ($accessToken, $oAuth2Client) {
                /** @var AzureResourceOwner $resourceOwner */
                $resourceOwner = $oAuth2Client->fetchUserFromToken($accessToken);

                // Extract user data from Azure
                $email = $resourceOwner->claim('email') ?? $resourceOwner->claim('upn');
                $azureObjectId = $resourceOwner->getId();
                $firstName = $resourceOwner->claim('given_name') ?? '';
                $lastName = $resourceOwner->claim('family_name') ?? '';
                $jobTitle = $resourceOwner->claim('jobTitle');
                $department = $resourceOwner->claim('department');

                // Find or create user
                $user = $this->userRepository->findByAzureObjectId($azureObjectId);

                if (!$user instanceof User) {
                    $user = $this->userRepository->findOneBy(['email' => $email]);
                }

                if (!$user) {
                    // Create new user
                    $user = new User();
                    $user->setEmail($email);
                    $user->setIsVerified(true);
                }

                // Update user data from Azure
                $user->setAzureObjectId($azureObjectId);
                $user->setAzureTenantId($resourceOwner->claim('tid'));
                $user->setAuthProvider('azure_oauth');
                $user->setFirstName($firstName);
                $user->setLastName($lastName);

                if ($jobTitle) {
                    $user->setJobTitle($jobTitle);
                }
                if ($department) {
                    $user->setDepartment($department);
                }

                // Store additional Azure metadata
                $user->setAzureMetadata([
                    'upn' => $resourceOwner->claim('upn'),
                    'oid' => $azureObjectId,
                    'tid' => $resourceOwner->claim('tid'),
                    'preferred_username' => $resourceOwner->claim('preferred_username'),
                ]);

                $user->setLastLoginAt(new DateTimeImmutable());
                $user->setUpdatedAt(new DateTimeImmutable());

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Redirect to dashboard after successful login
        $targetUrl = $this->router->generate('app_home');

        return new RedirectResponse($targetUrl);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = strtr($exception->getMessageKey(), $exception->getMessageData());

        return new RedirectResponse(
            $this->router->generate('app_login', ['error' => $message])
        );
    }

    /**
     * Called when authentication is needed, but it's not sent.
     * This redirects to the 'login'.
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse(
            $this->router->generate('app_login'),
            Response::HTTP_TEMPORARY_REDIRECT
        );
    }
}
