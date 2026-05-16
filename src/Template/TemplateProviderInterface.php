<?php

declare(strict_types=1);

namespace App\Template;

/**
 * Contract for a SystemTemplate provider.
 *
 * Implementations are auto-tagged via `_instanceof` (see config/services.yaml)
 * with `system_template.provider` and discovered by SystemTemplateRegistry
 * at container boot.
 *
 * Foundation P-14 (S5 Junior-ISB-Audit). A provider groups a coherent set
 * of templates for one domain (ISO 27005 threats, BSI hazards, GDPR VVTs,
 * ISO 27001 policies, hyperscaler suppliers, industry module profiles).
 */
interface TemplateProviderInterface
{
    /**
     * @return iterable<SystemTemplate>
     */
    public function provide(): iterable;
}
