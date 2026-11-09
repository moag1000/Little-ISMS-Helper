<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * Links that leave the browser session — e-mails, supplier questionnaire links, the
 * Trust Center address, asset QR labels — are built from the public address in
 * DEFAULT_URI, never from the Host header of whatever request happened to trigger them.
 *
 * Without it, a request with `Host: attacker.example` that raises an incident escalation
 * sends every manager a mail whose button points at the attacker (password phishing with
 * a genuine sender). Symfony's router takes the host from the current request; this
 * generator swaps in a context built from DEFAULT_URI for the one call and restores it.
 *
 * DEFAULT_URI still at the `.env` placeholder (localhost): the request host is used only
 * when trusted_hosts (SYMFONY_TRUSTED_HOSTS) vouches for it; otherwise the placeholder
 * stays and the health page (Monitoring > Health, environment card) names the missing
 * setting. `tests/Security/PublicLinksFromDefaultUriTest` keeps every mail/link builder
 * on this class.
 */
final class PublicUrlGenerator implements UrlGeneratorInterface
{
    private const PLACEHOLDER_HOSTS = ['', 'localhost', '127.0.0.1', '::1', '[::1]'];

    public function __construct(
        #[Autowire(service: 'router')]
        private readonly UrlGeneratorInterface $router,
        #[Autowire('%env(default::DEFAULT_URI)%')]
        private readonly ?string $defaultUri = null,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_URL): string
    {
        $current = $this->router->getContext();
        $public = $this->publicContext($current);
        if ($public === null) {
            return $this->router->generate($name, $parameters, $referenceType);
        }

        $this->router->setContext($public);
        try {
            return $this->router->generate($name, $parameters, $referenceType);
        } finally {
            $this->router->setContext($current);
        }
    }

    public function setContext(RequestContext $context): void
    {
        $this->router->setContext($context);
    }

    public function getContext(): RequestContext
    {
        return $this->router->getContext();
    }

    /** True when DEFAULT_URI carries a real public address (not empty, not localhost). */
    public function isConfigured(): bool
    {
        return $this->parsedDefaultUri() !== null;
    }

    /**
     * Problems an operator has to fix in `.env.local`, as translation keys of the
     * `monitoring` domain. Empty when links and Host checks are safe.
     *
     * @return list<string>
     */
    public function configurationIssues(): array
    {
        $issues = [];
        $uri = $this->parsedDefaultUri();
        if ($uri === null) {
            $issues[] = 'monitoring.public_address.default_uri_missing';
        }
        $patterns = Request::getTrustedHosts();
        if ($patterns === []) {
            $issues[] = 'monitoring.public_address.trusted_hosts_missing';

            return $issues;
        }
        // Symfony wraps each pattern as {pattern}i: an unanchored "isms.example.org"
        // also lets "isms.example.org.attacker.example" through.
        foreach ($patterns as $pattern) {
            $inner = substr($pattern, 1, -2);
            if (!str_starts_with($inner, '^') || !str_ends_with($inner, '$')) {
                $issues[] = 'monitoring.public_address.trusted_hosts_unanchored';
                break;
            }
        }
        if ($uri !== null) {
            $matches = array_filter($patterns, static fn (string $p): bool => preg_match($p, $uri['host']) === 1);
            if ($matches === []) {
                $issues[] = 'monitoring.public_address.default_uri_not_trusted';
            }
        }

        return $issues;
    }

    private function publicContext(RequestContext $current): ?RequestContext
    {
        $uri = $this->parsedDefaultUri();
        if ($uri === null) {
            // Placeholder DEFAULT_URI: the request host is only good enough when the
            // trusted_hosts check already rejected every other Host header. Otherwise
            // the link points at the placeholder — broken, but never at a foreign host.
            if (Request::getTrustedHosts() !== []) {
                return null;
            }
            $uri = $this->parsedDefaultUri(allowPlaceholder: true)
                ?? ['scheme' => 'http', 'host' => 'localhost', 'port' => null, 'path' => ''];
        }

        $context = new RequestContext(
            $uri['path'],
            'GET',
            $uri['host'],
            $uri['scheme'],
            $uri['scheme'] === 'http' ? ($uri['port'] ?? 80) : 80,
            $uri['scheme'] === 'https' ? ($uri['port'] ?? 443) : 443,
        );
        // Keep _locale and the other route defaults the current request set.
        $context->setParameters($current->getParameters());

        return $context;
    }

    /**
     * @return array{scheme: string, host: string, port: ?int, path: string}|null
     */
    private function parsedDefaultUri(bool $allowPlaceholder = false): ?array
    {
        $raw = trim((string) $this->defaultUri);
        $parts = $raw === '' ? false : parse_url($raw);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (!in_array($scheme, ['http', 'https'], true)
            || (!$allowPlaceholder && in_array($host, self::PLACEHOLDER_HOSTS, true))) {
            return null;
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
            'path' => rtrim($parts['path'] ?? '', '/'),
        ];
    }
}
