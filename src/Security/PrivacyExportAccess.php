<?php

declare(strict_types=1);

namespace App\Security;

/**
 * The one rule for privacy exports (GDPR records leave the tool as a file):
 * the data protection officer or a manager — VVT incl. the BfDI format, DPIA,
 * data breaches, data subject requests, consents. ROLE_ADMIN reaches both via
 * the hierarchy; reviewer roles (auditor, group CISO) do not, they get the
 * audit package. The answer to one data subject (DSR "export personal data")
 * is an action on that request, not an export of the register.
 *
 * Use: #[IsGranted(new Expression(PrivacyExportAccess::RULE))].
 * tests/Security/PrivacyExportAccessTest discovers every export action.
 */
final class PrivacyExportAccess
{
    public const string RULE = 'is_granted("ROLE_DPO") or is_granted("ROLE_MANAGER")';
}
