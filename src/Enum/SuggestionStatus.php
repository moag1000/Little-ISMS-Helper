<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * pending → accepted | rejected (a person decided) | superseded (the
 * condition no longer holds or a newer suggestion replaced it).
 */
enum SuggestionStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
