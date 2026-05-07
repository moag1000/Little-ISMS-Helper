<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Blocks URLs resolving to internal/private IP ranges to prevent SSRF attacks.
 * Per OWASP A10:2021, CWE-918, NIS2 Art. 21(2)(d).
 *
 * Blocks:
 *  - Loopback: 127.0.0.0/8, ::1
 *  - Link-Local: 169.254.0.0/16, fe80::/10
 *  - Private IPv4: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
 *  - Unique Local IPv6: fc00::/7
 *  - Cloud-Metadata: 169.254.169.254 (AWS/GCP), 100.100.100.200 (Alibaba)
 *  - DNS-resolution failures (defense against DNS-pinning attacks)
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class NoInternalIp extends Constraint
{
    public string $message = 'security.url.no_internal_ip';
}
