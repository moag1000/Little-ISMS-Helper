<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * Re-auth handler for AccessDeniedException raised by IsGranted-style checks
 * on already-authenticated users with RememberMe tokens.
 *
 * The bulk of routes hit Symfony's AccessListener first (which routes to
 * the entry_point, not here); this handler covers the secondary path where
 * a controller-level #[IsGranted] denies the request.
 */
final class ReauthAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(
        private readonly ReauthChallengeResponder $responder,
    ) {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        return $this->responder->tryRespond($request);
    }
}
