<?php

declare(strict_types=1);

namespace App\Template;

/**
 * Immutable description of a system-supplied data template.
 *
 * Foundation P-14 (Junior-ISB-Audit S5). Each template represents one
 * pre-fabricated starter record — or a coherent batch of records — that a
 * tenant can adopt to skip the 3-5 day "starting from zero" workshop.
 *
 * Two flavours are supported:
 *  - **Single-record templates** ($prefill set, $items=null): one Entity
 *    is materialised with the prefilled property values.
 *  - **Bulk templates** ($items set, $prefill kept empty/common-defaults):
 *    N Entities are materialised, each receiving the merge of $prefill and
 *    one row from $items. Used by Iso27005 / BSI threat catalogs.
 *
 * Provider classes (see `App\Template\Provider\*`) register their template
 * instances with `SystemTemplateRegistry` during container boot via the
 * `system_template.provider` autotag.
 */
final class SystemTemplate
{
    /**
     * @param string                $key         Stable identifier, e.g. "vvt.standard.recruiting".
     * @param class-string          $entityClass FQCN of the entity this template fabricates.
     * @param string|null           $module      Required module key (e.g. "privacy"); null = always available.
     * @param string                $language    ISO-639-1 language code ("de", "en") for the localised content.
     * @param string                $name        Human label, shown in selector modal.
     * @param string                $description Long-form description, shown as helper text in modal.
     * @param array<string, mixed>  $prefill     Property-value map applied to every materialised entity.
     * @param list<array<string, mixed>>|null $items One row per record to create (bulk mode). null = single record.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $entityClass,
        public readonly ?string $module,
        public readonly string $language,
        public readonly string $name,
        public readonly string $description,
        public readonly array $prefill,
        public readonly ?array $items = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>> Effective per-record property maps.
     *                                    Single-record templates yield a 1-item list.
     */
    public function records(): array
    {
        if ($this->items === null) {
            return [$this->prefill];
        }

        $records = [];
        foreach ($this->items as $item) {
            $records[] = array_merge($this->prefill, $item);
        }

        return $records;
    }
}
