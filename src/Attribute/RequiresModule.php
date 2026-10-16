<?php

declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * Declares that a controller (class-level) or a single action (method-level)
 * belongs to an optional module from config/modules.yaml.
 *
 * Enforced centrally by {@see \App\EventListener\RequiresModuleListener}: when
 * the module is not active for the current tenant, HTML requests are redirected
 * to the dashboard with a flash message and JSON/XHR requests receive a 403
 * JSON body. The attribute is repeatable; every listed module must be active.
 *
 *   #[RequiresModule('nis2_dora')]
 *   final class DoraRegisterExportController extends AbstractController
 *
 * CI gate `scripts/quality/check_module_gating.py` (controller pass) verifies
 * that controllers serving framework-specific features carry this attribute
 * or an explicit `checkModuleActive()` call.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class RequiresModule
{
    public function __construct(
        public string $module,
    ) {
    }
}
