<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

/** The proposal no longer fits its record; the queue marks it superseded. */
final class StaleSuggestionException extends \RuntimeException
{
}
