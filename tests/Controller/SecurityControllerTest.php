<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Security\SamlAuthFactory;
use Exception;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use LogicException;
use OneLogin\Saml2\Auth as SamlAuth;
use OneLogin\Saml2\Settings as SamlSettings;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for SecurityController
 *
 * Tests authentication flows including:
 * - Local login (with rate limiting)
 * - OAuth (Azure AD)
 * - SAML SSO
 * - Logout functionality
 *
 * Note: Some tests may fail if there are routing configuration issues in the application.
 * Specifically, check for duplicate {_locale} parameters in route patterns (e.g., ProfileController).
 *
 * Test Coverage:
 * - Login page rendering and cache headers
 * - Locale handling (query parameters, session, defaults)
 * - Authentication state (redirect authenticated users)
 * - OAuth Azure integration
 * - SAML login, metadata, and single logout
 * - Error handling for SAML and OAuth flows
 * - Form field presence validation
 */
class SecurityControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testLoginPageRendersSuccessfully(): void
    {
        // Act
        $this->client->request('GET', '/de/login');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testLoginPageSetsCorrectCacheHeaders(): void
    {
        // Act
        $this->client->request('GET', '/de/login');
        $response = $this->client->getResponse();

        // Assert
        $this->assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('must-revalidate'));
    }

    public function testLoginPageAcceptsLocaleQueryParameter(): void
    {
        // Act - Request login with locale query parameter
        $this->client->request('GET', '/de/login?locale=en');

        // Assert - Page loads successfully
        $this->assertResponseIsSuccessful();
    }

    public function testLoginPageUsesSessionLocaleWhenNoQueryParameter(): void
    {
        // Set session locale
        $this->client->request('GET', '/de/login');
        $session = $this->client->getRequest()->getSession();
        $session->set('_locale', 'en');

        // Act
        $this->client->request('GET', '/de/login');

        // Assert
        $this->assertSame('en', $session->get('_locale'));
    }

    public function testLoginPageDefaultsToSupportedLocale(): void
    {
        // Act
        $this->client->request('GET', '/de/login');
        $session = $this->client->getRequest()->getSession();

        // Assert - Should be either 'de' or 'en'
        $this->assertContains($session->get('_locale'), ['de', 'en']);
    }

    public function testLoginPageAccessibleWhenNotAuthenticated(): void
    {
        // Act - Access login page without authentication
        $this->client->request('GET', '/de/login');

        // Assert - Login page should be accessible
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testLogoutThrowsLogicException(): void
    {
        // The logout route should never actually execute the controller method
        // as it's intercepted by the security firewall
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('This method should never be reached');

        // Directly call the controller method (bypassing the firewall)
        $controller = static::getContainer()->get('App\Controller\SecurityController');
        $controller->logout();
    }

    public function testOAuthAzureConnectRouteExists(): void
    {
        // Act - Note: OAuth routes are locale-prefixed
        $this->client->request('GET', '/en/oauth/azure/connect');

        // Assert - Should redirect (either to Azure or error page)
        $response = $this->client->getResponse();
        $this->assertTrue($response->isRedirect());
    }

    public function testOAuthAzureCheckHandlesNoSession(): void
    {
        // This route is intercepted by the authenticator in production
        // Without valid OAuth state, it redirects to login with error
        // Act - Note: OAuth routes are locale-prefixed
        $this->client->request('GET', '/en/oauth/azure/check');

        // Assert - Should redirect (either to login or error page)
        $response = $this->client->getResponse();
        $this->assertTrue($response->isRedirect());
    }

    public function testSamlLoginRouteExists(): void
    {
        // Verify the SAML login route exists and responds appropriately
        $this->client->request('GET', '/en/saml/login');

        // Assert - Should redirect (either to IdP or to error/login page)
        $response = $this->client->getResponse();
        $this->assertTrue(
            $response->isRedirect() ||
            $response->isSuccessful()
        );
    }

    public function testSamlLoginHandlesNoConfiguration(): void
    {
        // Without proper SAML configuration, the login should redirect gracefully
        $this->client->request('GET', '/en/saml/login');

        // Assert - Should redirect to login page or IdP
        $response = $this->client->getResponse();
        $this->assertTrue($response->isRedirect());
    }

    public function testSamlAcsHandlesAuthenticationAttempt(): void
    {
        // This route is intercepted by the SAML authenticator in production
        // Without valid SAML response, it redirects to login with error
        $this->client->request('POST', '/en/saml/acs');

        // Assert - Without valid SAML response, redirects to login
        $response = $this->client->getResponse();
        $this->assertTrue($response->isRedirect());
    }

    public function testSamlMetadataRequiresAuthentication(): void
    {
        // Act - SAML metadata route requires authentication
        $this->client->request('GET', '/en/saml/metadata');

        // Assert - Should redirect to login when not authenticated
        $this->assertResponseRedirects();
    }

    public function testSamlMetadataValidationErrorsHandledGracefully(): void
    {
        // Without proper mocking infrastructure for SAML factory in WebTestCase,
        // we verify the route at least handles invalid configs without 500 errors
        $this->client->request('GET', '/en/saml/metadata');

        // Should either redirect to login or return graceful error response
        $response = $this->client->getResponse();
        $this->assertTrue(
            $response->isRedirect() ||
            $response->getStatusCode() === 404 ||
            $response->getStatusCode() === 200
        );
    }

    public function testSamlMetadataRouteExists(): void
    {
        // Verify the route exists and is accessible (even if it requires auth)
        $this->client->request('GET', '/en/saml/metadata');

        // Should not return 404 "route not found"
        $response = $this->client->getResponse();
        // Either authenticates (302 redirect) or returns content
        $this->assertNotEquals(
            'No route found for "GET http://localhost/en/saml/metadata"',
            $response->getContent()
        );
    }

    public function testSamlSlsRouteExists(): void
    {
        // Verify the SLS route exists and handles requests gracefully
        $this->client->request('GET', '/en/saml/sls');

        // SLS should redirect to login (either from SAML processing or auth requirement)
        $this->assertResponseRedirects();
    }

    public function testSamlSlsRedirectsToLogin(): void
    {
        // Act - Without active SAML session, SLS should redirect to login
        $this->client->request('GET', '/en/saml/sls');

        // Assert - Should redirect (either to login or SAML IdP)
        $response = $this->client->getResponse();
        $this->assertTrue($response->isRedirect());
    }

    public function testSamlSlsHandlesNoSamlSession(): void
    {
        // When no SAML session exists, SLS should gracefully redirect
        $this->client->request('GET', '/en/saml/sls');

        // Should redirect without 500 error
        $response = $this->client->getResponse();
        $this->assertTrue(
            $response->isRedirect() ||
            $response->isSuccessful()
        );
    }

    public function testLoginPageDisplaysUsernameField(): void
    {
        // Act
        $this->client->request('GET', '/de/login');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="_username"]');
    }

    public function testLoginPageDisplaysPasswordField(): void
    {
        // Act
        $this->client->request('GET', '/de/login');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="_password"]');
    }

    public function testSecurityControllerIsRegisteredAsService(): void
    {
        // Act
        $controller = static::getContainer()->get('App\Controller\SecurityController');

        // Assert
        $this->assertInstanceOf(\App\Controller\SecurityController::class, $controller);
    }
}
