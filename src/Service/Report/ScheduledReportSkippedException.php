<?php

declare(strict_types=1);

namespace App\Service\Report;

/**
 * A scheduled report must not run now (module off, organisation or creator no longer entitled). The message is the
 * translation key of the reason (domain scheduled_reports).
 */
final class ScheduledReportSkippedException extends \RuntimeException
{
}
