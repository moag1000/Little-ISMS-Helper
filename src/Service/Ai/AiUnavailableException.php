<?php

declare(strict_types=1);

namespace App\Service\Ai;

/** The endpoint did not answer usably (network, HTTP error, empty or non-JSON answer). */
final class AiUnavailableException extends \RuntimeException
{
}
