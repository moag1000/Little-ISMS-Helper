<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\SamlAuthFactory;
use App\Security\SamlMessageGuard;
use OneLogin\Saml2\Auth;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

/**
 * Building blocks for SSO authenticator unit tests (S9): a SAML message guard
 * on an in-memory cache, an ACS POST that answers a remembered request, and
 * the directory id the env Azure tests sign in from.
 */
final class SsoTestKit
{
    public const string AZURE_TENANT = '11111111-2222-3333-4444-555555555555';

    public static function samlGuard(): SamlMessageGuard
    {
        return new SamlMessageGuard(new ArrayAdapter());
    }

    /**
     * ACS request whose SAMLResponse answers $requestId. $remember = false
     * builds an unsolicited / unknown-request response.
     */
    public static function acsRequest(SamlMessageGuard $guard, string $requestId = 'ONELOGIN_req_1', bool $remember = true, bool $withInResponseTo = true, bool $sameBrowser = true): Request
    {
        $nonce = $remember ? $guard->rememberRequest($requestId) : '';
        $attr = $withInResponseTo ? ' InResponseTo="' . htmlspecialchars($requestId, ENT_XML1) . '"' : '';
        $xml = '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" ID="_r1" Version="2.0"'
            . $attr . '></samlp:Response>';

        // The browser that started the login carries the binding cookie (S14);
        // $sameBrowser = false posts the response into another browser.
        return new Request(
            request: ['SAMLResponse' => base64_encode($xml)],
            cookies: $sameBrowser && $nonce !== '' ? [SamlMessageGuard::BINDING_COOKIE => $nonce] : [],
        );
    }

    /**
     * The factory is only asked for an Auth when the authenticator has none
     * injected; tests inject a stub Auth by reflection, so this one never builds.
     */
    public static function samlFactory(): SamlAuthFactory
    {
        return new SamlAuthFactory(
            new \Symfony\Component\Routing\Generator\UrlGenerator(
                new \Symfony\Component\Routing\RouteCollection(),
                new \Symfony\Component\Routing\RequestContext(),
            ),
            'test',
            '',
            '',
            defaultUri: 'https://isms.example.org',
        );
    }

    public static function injectAuth(object $authenticator, Auth $auth): void
    {
        (new \ReflectionProperty($authenticator::class, 'samlAuth'))->setValue($authenticator, $auth);
    }
}
