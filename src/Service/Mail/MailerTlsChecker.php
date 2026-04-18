<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Mailer TLS Configuration Checker
 *
 * ISB MINOR-4 / DSGVO Art. 32 + ISO 27001:2022 A.5.34:
 * Verifies that the configured Symfony mailer DSN enforces transport
 * encryption before any scheduled report is sent. A plain `smtp://` DSN
 * without an `encryption=tls|ssl` query string is rejected.
 *
 * Accepted scheme/shape matrix:
 *  - smtps://...                 → always TLS (implicit)
 *  - smtp+tls://...              → aliased TLS
 *  - smtp://...?encryption=tls   → STARTTLS
 *  - smtp://...?encryption=ssl   → legacy SSL (accepted)
 *  - sendgrid+api://...          → HTTPS API transport
 *  - ses+api://...               → HTTPS API transport
 *  - postmark+api://...          → HTTPS API transport
 *  - mailgun+api://...           → HTTPS API transport
 *  - null://null                 → test/dev no-op
 *  - mailer://...                → locally handled
 *  - sendmail://...              → local MTA (no remote TLS)
 *
 * Rejected:
 *  - smtp://host:25              → plain, no encryption
 *  - smtp://host:587             → plain, no encryption param
 */
final class MailerTlsChecker
{
    public function __construct(
        private readonly string $mailerDsn,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function isTlsConfigured(): bool
    {
        $dsn = trim($this->mailerDsn);
        if ($dsn === '') {
            return false;
        }

        $scheme = strtolower((string) parse_url($dsn, PHP_URL_SCHEME));

        // Locally-delivered / test schemes: no remote TLS needed.
        if (in_array($scheme, ['null', 'sendmail', 'mailer'], true)) {
            return true;
        }

        // Implicit-TLS schemes.
        if (in_array($scheme, ['smtps', 'smtp+tls'], true)) {
            return true;
        }

        // HTTPS-based API transports.
        if (in_array($scheme, [
            'sendgrid+api',
            'ses+api',
            'postmark+api',
            'mailgun+api',
            'sendgrid+https',
            'ses+https',
            'postmark+https',
            'mailgun+https',
            'brevo+api',
            'mandrill+api',
        ], true)) {
            return true;
        }

        // Plain smtp: require explicit encryption query param.
        if ($scheme === 'smtp') {
            $query = (string) parse_url($dsn, PHP_URL_QUERY);
            if ($query === '') {
                return false;
            }
            parse_str($query, $params);
            $encryption = isset($params['encryption']) ? strtolower((string) $params['encryption']) : '';
            return in_array($encryption, ['tls', 'ssl'], true);
        }

        // Unknown scheme: refuse rather than assume.
        return false;
    }

    /**
     * @throws \RuntimeException if TLS is not configured
     */
    public function assertTlsConfigured(): void
    {
        if ($this->isTlsConfigured()) {
            return;
        }

        $message = $this->translator !== null
            ? $this->translator->trans('email.tls_required_error', [], 'scheduled_reports')
            : 'Mailer DSN does not enforce TLS. Scheduled report sending is blocked per DSGVO Art. 32 / ISO 27001 A.5.34.';

        throw new \RuntimeException($message);
    }

    public function getDsnSchemeForDiagnostics(): string
    {
        $scheme = parse_url(trim($this->mailerDsn), PHP_URL_SCHEME);
        return is_string($scheme) ? strtolower($scheme) : '';
    }
}
