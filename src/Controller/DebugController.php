<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * TEMPORARY DEBUG CONTROLLER - DELETE AFTER TESTING
 * Helps diagnose login issues
 */
class DebugController extends AbstractController
{
    #[Route('/debug-auth', name: 'debug_auth')]
    public function debugAuth(Request $request): Response
    {
        $user = $this->getUser();
        $session = $request->getSession();

        $debug = [
            'timestamp' => date('Y-m-d H:i:s'),
            'user' => [
                'authenticated' => $user !== null,
                'email' => $user ? $user->getUserIdentifier() : 'not logged in',
                'roles' => $user ? $user->getRoles() : [],
            ],
            'session' => [
                'id' => $session->getId(),
                'started' => $session->isStarted(),
                'attributes' => $session->all(),
            ],
            'cookies' => $request->cookies->all(),
            'server' => [
                'HTTPS' => $request->server->get('HTTPS'),
                'REQUEST_SCHEME' => $request->server->get('REQUEST_SCHEME'),
            ],
        ];

        return new Response(
            '<html><body><h1>Debug Info</h1><pre>' .
            print_r($debug, true) .
            '</pre><p><a href="/login">Back to Login</a></p></body></html>',
            200,
            ['Content-Type' => 'text/html']
        );
    }
}
