<?php

declare(strict_types=1);

namespace App\Twig;

use App\Form\SectionMapInterface;
use Symfony\Component\Form\FormView;
use Twig\Attribute\AsTwigFunction;

/**
 * SectionPolicyExtension — S4 Foundation P-2.
 *
 * Provides Twig functions to integrate FormTypes implementing
 * {@see SectionMapInterface} with the `_auto_form.html.twig` renderer.
 *
 * Available functions:
 * - `form_section_map(form)` — returns the section-map declared by the
 *   FormType's `getSectionMap()` static method, or `null` if the FormType
 *   does not implement {@see SectionMapInterface}. Twig templates can
 *   then iterate the map and render fields by section.
 * - `form_section_fields(form, sectionKey)` — convenience accessor that
 *   returns the FormView children matching the field names listed in the
 *   given section. Skips fields not present on the form (defensive — a
 *   FormType may omit fields conditionally based on Module-Gating).
 * - `form_section_unmapped(form)` — returns FormView children that are
 *   NOT covered by any section. In strict mode the CI-gate enforces this
 *   list to be empty; the template emits a deprecation warning in dev-env
 *   if any unmapped fields slip through.
 */
class SectionPolicyExtension
{
    /**
     * Resolve the section-map for a Symfony form.
     *
     * @return array<string, list<string>>|null section-key => list of field-names
     */
    #[AsTwigFunction('form_section_map')]
    public function getSectionMap(FormView $form): ?array
    {
        $formType = $this->resolveFormTypeClass($form);
        if ($formType === null) {
            return null;
        }
        if (!is_subclass_of($formType, SectionMapInterface::class)) {
            return null;
        }

        /** @var class-string<SectionMapInterface> $formType */
        return $formType::getSectionMap();
    }

    /**
     * Return the FormView children matching the field names declared in the
     * given section. Fields not present on the form are silently skipped
     * (Module-Gating may hide them conditionally).
     *
     * @return list<FormView>
     */
    #[AsTwigFunction('form_section_fields')]
    public function getSectionFields(FormView $form, string $sectionKey): array
    {
        $map = $this->getSectionMap($form);
        if ($map === null || !isset($map[$sectionKey])) {
            return [];
        }

        $fields = [];
        foreach ($map[$sectionKey] as $fieldName) {
            if (isset($form->children[$fieldName])) {
                $fields[] = $form->children[$fieldName];
            }
        }
        return $fields;
    }

    /**
     * Return FormView children that are NOT covered by any section in the
     * section-map. Used by `_auto_form.html.twig` to detect leakage into
     * the legacy "Sonstiges" bucket and emit a deprecation warning.
     *
     * Hidden fields (`_token`, fields with `block_prefixes` containing
     * `hidden`) are excluded from the unmapped list.
     *
     * @return list<FormView>
     */
    #[AsTwigFunction('form_section_unmapped')]
    public function getUnmappedFields(FormView $form): array
    {
        $map = $this->getSectionMap($form);
        if ($map === null) {
            return [];
        }

        $mapped = [];
        foreach ($map as $fields) {
            foreach ($fields as $f) {
                $mapped[$f] = true;
            }
        }

        $unmapped = [];
        foreach ($form->children as $name => $child) {
            if (isset($mapped[$name])) {
                continue;
            }
            $blockPrefixes = $child->vars['block_prefixes'] ?? [];
            if (in_array('hidden', $blockPrefixes, true)) {
                continue;
            }
            $unmapped[] = $child;
        }
        return $unmapped;
    }

    /**
     * Determine the FormType class for a given FormView.
     *
     * Symfony stores the FormType's FQCN in `block_prefixes` (second entry
     * for `AbstractType`), but for our convention we explicitly read the
     * `data_class` and then look at the form's `inner_type_class` set by
     * Symfony when the form was built.
     *
     * Fallback: walk `block_prefixes` and look for an entry that resolves
     * to a class implementing {@see SectionMapInterface}.
     */
    private function resolveFormTypeClass(FormView $form): ?string
    {
        // FormView->vars carries the resolved type chain in 'unique_block_prefix'
        // but not the FQCN. Symfony exposes the FormType FQCN via the form
        // builder's getType()->getInnerType()::class — at render-time we receive
        // a FormView. The reliable bridge is the 'form_type_class' var that
        // Symfony 7.2+ exposes on the FormView via a custom FormTypeExtension.
        if (isset($form->vars['form_type_class']) && is_string($form->vars['form_type_class'])) {
            return $form->vars['form_type_class'];
        }

        // Fallback: walk block_prefixes (last entry before "form" is the type's
        // block_prefix, but we need the FQCN — try matching against known
        // SectionMapInterface implementations in App\Form namespace).
        $blockPrefixes = $form->vars['block_prefixes'] ?? [];
        foreach (array_reverse($blockPrefixes) as $prefix) {
            if (!is_string($prefix) || $prefix === 'form') {
                continue;
            }
            // Convention: block_prefix derives from class basename (snake_case).
            $candidate = $this->guessFqcnFromBlockPrefix($prefix);
            if ($candidate !== null && is_subclass_of($candidate, SectionMapInterface::class)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Best-effort conversion of a Symfony block_prefix (snake_case) to
     * an App\Form FQCN. Returns null if no matching class exists.
     */
    private function guessFqcnFromBlockPrefix(string $prefix): ?string
    {
        // snake_case → PascalCase + "Type"
        $pascal = str_replace(' ', '', ucwords(str_replace('_', ' ', $prefix)));
        $candidates = [
            'App\\Form\\' . $pascal . 'Type',
            'App\\Form\\' . $pascal,
        ];
        foreach ($candidates as $fqcn) {
            if (class_exists($fqcn)) {
                return $fqcn;
            }
        }
        return null;
    }
}
