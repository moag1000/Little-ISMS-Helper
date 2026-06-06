<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use Symfony\Component\HttpFoundation\Request;

/**
 * Helpers for the in-page drawer / form-modal form pattern.
 *
 * Detail / Edit / New actions render a slim shell (drawer or form-modal) when
 * the request comes from a Turbo Frame, and the full page otherwise (direct URL
 * / bookmark / no-JS fallback). See
 * docs/superpowers/specs/2026-06-06-form-drawer-modal-overhaul-design.md.
 */
trait InPageFormTrait
{
    /**
     * True when Turbo is loading this action into a named frame (the drawer or
     * form-modal host) — Turbo sets the `Turbo-Frame` request header to the
     * target frame id.
     */
    protected function isTurboFrameRequest(Request $request): bool
    {
        return $request->headers->has('Turbo-Frame');
    }

    /** The frame id Turbo is targeting (e.g. "fa-drawer"), or null. */
    protected function turboFrameId(Request $request): ?string
    {
        return $request->headers->get('Turbo-Frame');
    }
}
