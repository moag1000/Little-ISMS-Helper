<?php

declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * A field of an optional module on an entity another module owns (DORA
 * fields on an incident, FAIR fields on a risk, AI-agent fields on an asset).
 *
 * The web form only adds it while the module is active; REST and the
 * assistant (MCP) ignore it in a request body while the module is off
 * ({@see \App\Api\ModuleFieldContextBuilder}). The form side and this
 * attribute are kept in step by scripts/quality/check_api_module_fields.py.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class ModuleField
{
    public function __construct(
        public string $module,
    ) {
    }
}
