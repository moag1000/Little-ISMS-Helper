<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class NoInternalIpValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NoInternalIp) {
            throw new UnexpectedTypeException($constraint, NoInternalIp::class);
        }
        if ($value === null || $value === '') {
            return;
        }

        $url = (string) $value;
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false) {
            return; // Url-constraint should catch malformed
        }

        // parse_url wraps IPv6 addresses in brackets (e.g. "[::1]"); strip them for filter_var
        $host = ltrim(rtrim($host, ']'), '[');

        // If hostname not IP, resolve it
        $isIp = (bool) filter_var($host, FILTER_VALIDATE_IP);
        $ip = $isIp ? $host : @gethostbyname($host);

        if (!$isIp && $ip === $host) {
            // DNS-resolution failed - block to be safe (DNS-pinning defense)
            $this->context->buildViolation('security.url.dns_resolution_failed')->addViolation();
            return;
        }

        // FILTER_FLAG_NO_PRIV_RANGE blocks 10/8, 172.16/12, 192.168/16, fc00::/7
        // FILTER_FLAG_NO_RES_RANGE blocks 0.0.0.0, 127.x, 169.254.x, 224-255.x, ::1, fe80::/10
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
