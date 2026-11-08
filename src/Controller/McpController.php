<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Mcp\McpServer;
use App\Mcp\McpSession;
use App\Mcp\McpSettings;
use App\Security\ApiTokenAuthenticator;
use App\Service\Suggestion\SuggestionText;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The assistant connection (MCP, Streamable HTTP, stateless): Claude
 * Desktop, Claude Code or any MCP client POSTs JSON-RPC messages to /mcp
 * with an API token of the person (Profile > API-Tokens) as bearer.
 *
 * Access: only bearer tokens (a browser session never reaches the tools,
 * so a cross-site POST cannot use them), only for organisations whose admin
 * switched the connection on, with the token user's roles and tenant scope.
 * No server-sent events and no session id — every request stands alone,
 * which works on shared hosting. Guide: docs/user-guide/KI_ASSISTENT_MCP.md.
 */
final class McpController extends AbstractController
{
    private const int MAX_BODY_BYTES = 1_000_000;

    public function __construct(
        private readonly McpServer $server,
        private readonly McpSettings $settings,
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    // @csrf-exempt: bearer-token endpoint; session-cookie requests are refused below, so a cross-site POST has no credentials
    #[Route('/mcp', name: 'app_mcp_endpoint', methods: ['POST'])]
    public function endpoint(Request $request): Response
    {
        $token = $request->attributes->get(ApiTokenAuthenticator::TOKEN_ATTRIBUTE);
        $user = $this->getUser();
        if (!$token instanceof ApiToken || !$user instanceof User || $token->getUser()?->getId() !== $user->getId()) {
            return new JsonResponse(
                self::error('Authentication required: send an API token as "Authorization: Bearer <token>".'),
                Response::HTTP_UNAUTHORIZED,
                ['WWW-Authenticate' => 'Bearer realm="little-isms-helper"'],
            );
        }
        if (!$this->sameOrigin($request)) {
            return new JsonResponse(self::error('Origin not allowed.'), Response::HTTP_FORBIDDEN);
        }
        $tenant = $user->getTenant();
        if ($tenant === null || ($token->getTenant() !== null && $token->getTenant()->getId() !== $tenant->getId())) {
            return new JsonResponse(self::error('The token belongs to no organisation.'), Response::HTTP_FORBIDDEN);
        }
        if (!$this->settings->isEnabled($tenant)) {
            return new JsonResponse(
                self::error('The assistant connection (MCP) is switched off for this organisation. An admin switches it on under Admin > Settings > AI.'),
                Response::HTTP_FORBIDDEN,
            );
        }

        $body = (string) $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return new JsonResponse(self::error('Request too large.', -32600), Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $message = json_decode($body, true);
        if (!is_array($message)) {
            return new JsonResponse(self::error('Parse error', -32700), Response::HTTP_BAD_REQUEST);
        }

        $locale = in_array($user->getLanguage(), ['de', 'en'], true) ? (string) $user->getLanguage() : SuggestionText::locale($tenant);
        $this->localeSwitcher->setLocale($locale);
        $session = new McpSession($user, $tenant, $token, $request->getSchemeAndHttpHost() . $request->getBasePath(), $locale);

        if (array_is_list($message)) {
            // JSON-RPC batch (protocol versions before 2025-06-18)
            $answers = array_values(array_filter(array_map(
                fn (mixed $m): ?array => is_array($m) ? $this->server->handle($m, $session) : self::error('Invalid request', -32600),
                $message,
            )));

            return $answers === [] ? new Response('', Response::HTTP_ACCEPTED) : new JsonResponse($answers);
        }

        $answer = $this->server->handle($message, $session);

        return $answer === null ? new Response('', Response::HTTP_ACCEPTED) : new JsonResponse($answer);
    }

    /** No server-to-client stream and no session to end: only POST is spoken here. */
    // @csrf-exempt: answers 405 and changes nothing
    #[Route('/mcp', name: 'app_mcp_endpoint_other', methods: ['GET', 'DELETE'])]
    public function other(): Response
    {
        return new Response('', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'POST']);
    }

    /** Spec: servers must check Origin (DNS rebinding). Desktop clients send none. */
    private function sameOrigin(Request $request): bool
    {
        $origin = (string) $request->headers->get('Origin', '');

        return $origin === '' || strcasecmp(rtrim($origin, '/'), $request->getSchemeAndHttpHost()) === 0;
    }

    /** @return array<string, mixed> */
    private static function error(string $message, int $code = -32001): array
    {
        return ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => $code, 'message' => $message]];
    }
}
