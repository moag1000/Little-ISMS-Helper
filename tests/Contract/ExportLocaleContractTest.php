<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Entity\ScheduledReport;
use App\Job\AsyncJobInterface;
use App\Job\JobContext;
use App\Message\Job\ExecuteJobMessage;
use App\MessageHandler\Job\ExecuteJobHandler;
use App\Service\Job\InRequestJobRunner;
use App\Service\Job\JobDispatcher;
use App\Service\Job\JobLocale;
use App\Service\Job\JobStatusService;
use App\Service\Job\MessengerJobRunner;
use App\Service\Report\ScheduledReportAttachment;
use App\Service\TenantContext;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Gate 102, PHP part (W10): an export is written in the language of the
 * person who asked for it, also when no request is around any more.
 *
 * Before, the seven export jobs ran in the Messenger worker's default locale
 * (an English user got a German VVT) and a scheduled report honoured its
 * language only in the mail text, not in the attached PDF/Excel. The
 * template part (text outside |trans, `<html lang>`) is
 * scripts/quality/check_pdf_i18n.py.
 */
final class ExportLocaleContractTest extends KernelTestCase
{
    /**
     * Every job whose class name says "Export" is found on disk, so a new
     * export job is covered without touching this test.
     *
     * @return list<class-string<AsyncJobInterface>>
     */
    private static function exportJobs(): array
    {
        $jobs = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Job/*Export*Job.php') ?: [] as $file) {
            $class = 'App\\Job\\' . basename($file, '.php');
            if (is_subclass_of($class, AsyncJobInterface::class)) {
                $jobs[] = $class;
            }
        }

        return $jobs;
    }

    #[Test]
    public function everyExportJobIsDispatchedWithTheRequestLocale(): void
    {
        $jobs = self::exportJobs();
        self::assertGreaterThanOrEqual(9, count($jobs), 'export jobs are discovered from src/Job');

        $request = new Request();
        $request->setLocale('en');
        $stack = new RequestStack();
        $stack->push($request);
        $jobLocale = new JobLocale(new LocaleSwitcher('de', []), $stack, ['de', 'en']);

        $seen = [];
        $inRequest = $this->createMock(InRequestJobRunner::class);
        $inRequest->method('dispatch')->willReturnCallback(
            function (string $class, array $args, string $id, Response $response) use (&$seen): Response {
                $seen[$class] = $args;

                return $response;
            },
        );
        $dispatcher = new JobDispatcher(
            $inRequest,
            $this->createStub(MessengerJobRunner::class),
            'in_request',
            $jobLocale,
        );

        foreach ($jobs as $job) {
            $dispatcher->dispatch($job, ['tenantId' => 1], 'probe', new Response());
            self::assertSame('en', $seen[$job]['locale'] ?? null, $job . ' must carry the dispatcher\'s locale');
        }

        // A locale the job names itself (certification bundle form) wins.
        $dispatcher->dispatch($jobs[0], ['locale' => 'de'], 'probe', new Response());
        self::assertSame('de', $seen[$jobs[0]]['locale']);
    }

    #[Test]
    public function bothRunnersExecuteTheJobInItsLocale(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $statuses = $container->get(JobStatusService::class);
        $switcher = $container->get(LocaleSwitcher::class);
        $jobLocale = $container->get(JobLocale::class);
        $default = $switcher->getLocale();
        $other = $default === 'en' ? 'de' : 'en';

        $probe = new class ($switcher) implements AsyncJobInterface {
            public ?string $seen = null;

            public function __construct(private readonly LocaleSwitcher $switcher)
            {
            }

            public function run(JobContext $ctx): void
            {
                $this->seen = $this->switcher->getLocale();
            }
        };
        $locator = new class ($probe) implements ContainerInterface {
            public function __construct(private readonly AsyncJobInterface $job)
            {
            }

            public function get(string $id): AsyncJobInterface
            {
                return $this->job;
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        // Messenger worker: no request, default locale until the job runs.
        $handler = new ExecuteJobHandler($statuses, $container->get(TenantContext::class), $locator, $jobLocale);
        $jobId = $statuses->create('test.export_locale', []);
        try {
            $handler(new ExecuteJobMessage($probe::class, ['locale' => $other], $jobId, null, true));
            self::assertSame($other, $probe->seen, 'the worker runs the job in the dispatcher\'s locale');
            self::assertSame($default, $switcher->getLocale(), 'and puts the worker\'s locale back');
        } finally {
            $statuses->delete($jobId);
        }

        // In-request runner (synchronous under CLI).
        $probe->seen = null;
        $runner = new InRequestJobRunner($statuses, $locator, new NullLogger(), $jobLocale);
        $jobId = $statuses->create('test.export_locale', []);
        try {
            $runner->dispatch($probe::class, ['locale' => $other], $jobId, new Response());
            self::assertSame($other, $probe->seen);
            self::assertSame($default, $switcher->getLocale());
        } finally {
            $statuses->delete($jobId);
        }
    }

    #[Test]
    public function scheduledReportAttachmentFollowsTheScheduleLanguage(): void
    {
        self::bootKernel();
        $attachment = static::getContainer()->get(ScheduledReportAttachment::class);

        $headers = [];
        foreach (['de', 'en'] as $locale) {
            $report = new ScheduledReport();
            $report->setReportType(ScheduledReport::TYPE_COMPLIANCE);
            $report->setFormat(ScheduledReport::FORMAT_EXCEL);
            $report->setLocale($locale);

            $bytes = $attachment->render($report, static fn (): array => ['report' => ['active' => true]], 'unused');
            $headers[$locale] = $this->firstRow($bytes);
        }

        self::assertSame(['Kennzahl', 'Wert'], array_slice($headers['de'][0], 0, 2));
        self::assertSame(['Metric', 'Value'], array_slice($headers['en'][0], 0, 2));
        self::assertSame('Ja', $headers['de'][1][1] ?? null);
        self::assertSame('Yes', $headers['en'][1][1] ?? null);
    }

    /**
     * @return list<list<mixed>>
     */
    private function firstRow(string $bytes): array
    {
        $file = tempnam(sys_get_temp_dir(), 'w10');
        file_put_contents($file, $bytes);
        try {
            $sheet = IOFactory::createReader('Xlsx')->load($file)->getActiveSheet();

            return [$sheet->rangeToArray('A1:B1')[0], $sheet->rangeToArray('A2:B2')[0]];
        } finally {
            unlink($file);
        }
    }
}
