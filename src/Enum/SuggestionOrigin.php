<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a suggestion comes from. Shown as a chip in the queue so the person
 * knows how much to trust it: a rule over the organisation's own data, an
 * inheritance from another framework, an import, or a language model.
 */
enum SuggestionOrigin: string
{
    case Rule = 'rule';
    case Inheritance = 'inheritance';
    case Import = 'import';
    case Ai = 'ai';
}
