<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\CspNonce;
use Twig\Attribute\AsTwigFunction;

/**
 * `csp_nonce()` — the per-request CSP nonce for inline scripts:
 *   <script nonce="{{ csp_nonce() }}">…</script>
 * Gate 67 (scripts/quality/check_native_dialogs.py) enforces it.
 */
final readonly class CspNonceExtension
{
    public function __construct(private CspNonce $cspNonce)
    {
    }

    #[AsTwigFunction('csp_nonce')]
    public function nonce(): string
    {
        return $this->cspNonce->get();
    }
}
