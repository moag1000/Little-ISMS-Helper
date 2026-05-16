<?php

declare(strict_types=1);

namespace App\Form;

/**
 * SectionMapInterface — S4 Foundation P-2 SectionPolicy
 *
 * Form types implementing this interface declare an explicit field-to-section
 * mapping. The `_auto_form.html.twig` template uses this map to render fields
 * in well-defined, regulatorily-meaningful sections (e.g. Overview, Recovery,
 * Team, Resources, Testing, Audit-Metadata) instead of dumping unmapped
 * fields into a generic "Sonstiges" / "Other fields" catch-all bucket.
 *
 * The catch-all has been the source of several regulatory findings:
 * - ISO 22301 Cl. 8.2.2 fields (RTO/RPO) buried under "Sonstiges"
 * - DORA-relevance flags hidden from supplier-management workflow
 * - BC-Exercise actualRtoAchieved / successCriteria not visually grouped
 *   with other Result-fields
 *
 * Section keys map 1:1 to translation keys `form.section.<key>` in the
 * `messages` translation domain. Field names match the form-builder
 * `->add('<name>', ...)` identifier.
 *
 * @see scripts/quality/check_form_sections.py — CI-gate that ensures every
 *      builder-added field appears in exactly one section and no section
 *      references undefined fields.
 */
interface SectionMapInterface
{
    /**
     * Return the section-to-field map for this form type.
     *
     * Example:
     *   return [
     *       'overview' => ['title', 'description', 'businessProcess'],
     *       'recovery' => ['rto', 'rpo', 'criticalAssets', 'recoveryProcedures'],
     *       'team'     => ['responseTeamMembers', 'crisisTeams', 'escalationLevels'],
     *   ];
     *
     * @return array<string, list<string>> section-key => list of field-names
     */
    public static function getSectionMap(): array;
}
