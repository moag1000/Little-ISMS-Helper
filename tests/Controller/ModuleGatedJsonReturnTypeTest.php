<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DPIAController;
use App\Controller\IncidentController;
use App\Controller\ProcessingActivityController;
use App\Controller\TrainingController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * RequiresModuleListener answers 403 JSON (instead of a 302 to the dashboard)
 * for fetch() callers without Accept header only when the action declares
 * `: JsonResponse`. Actions that return nothing but JSON must say so (#1043).
 */
final class ModuleGatedJsonReturnTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function jsonActions(): iterable
    {
        yield 'incident bcm impact' => [IncidentController::class, 'bcmImpactApi'];
        yield 'incident gdpr wizard result' => [IncidentController::class, 'gdprWizardResult'];
        yield 'dpia search' => [DPIAController::class, 'search'];
        yield 'dpia compliance report' => [DPIAController::class, 'complianceReport'];
        yield 'dpia validate' => [DPIAController::class, 'validate'];
        yield 'processing activity search' => [ProcessingActivityController::class, 'search'];
        yield 'processing activity compliance report' => [ProcessingActivityController::class, 'complianceReport'];
        yield 'processing activity validate' => [ProcessingActivityController::class, 'validate'];
        yield 'processing activity bulk status' => [ProcessingActivityController::class, 'bulkStatusChange'];
        yield 'training bulk assign' => [TrainingController::class, 'bulkAssign'];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('jsonActions')]
    public function jsonOnlyActionsDeclareJsonResponse(string $class, string $method): void
    {
        $type = (new ReflectionMethod($class, $method))->getReturnType();

        self::assertSame(JsonResponse::class, (string) $type);
    }
}
