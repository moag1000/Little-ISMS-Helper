<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Controller;
use App\Entity;
use App\Security\Voter\ApiTenantVoter;
use App\Security\Voter\IsmsSteeringVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * S4 (2026-11): what the web form forbids a role, REST and the assistant
 * (MCP, which runs the same API operations) forbid too.
 *
 * Every API resource that {@see ApiTenantVoter} decides and that can be
 * written is listed here with the controller actions that create and edit it
 * in the UI. The role those actions demand must be the role the voter demands
 * ({@see ApiTenantVoter::WRITE_ROLES}). A new writable resource fails here
 * until someone names its form.
 */
final class ApiWriteRoleParityTest extends TestCase
{
    /**
     * resource => list of [controller, method] that create/edit it in the UI;
     * null = no form exists (machine-fed log), the API is the only writer.
     *
     * @var array<class-string, list<array{class-string, string}>|null>
     */
    private const array FORMS = [
        Entity\BCExercise::class => [[Controller\BCExerciseController::class, 'new'], [Controller\BCExerciseController::class, 'edit']],
        Entity\BusinessContinuityPlan::class => [
            [Controller\BusinessContinuityPlanController::class, 'new'],
            [Controller\BusinessContinuityPlanController::class, 'edit'],
        ],
        Entity\ChangeRequest::class => [[Controller\ChangeRequestController::class, 'new'], [Controller\ChangeRequestController::class, 'edit']],
        Entity\ISMSContext::class => [[Controller\ContextController::class, 'edit']],
        Entity\ISMSObjective::class => [[Controller\ISMSObjectiveController::class, 'new'], [Controller\ISMSObjectiveController::class, 'edit']],
        Entity\InterestedParty::class => [
            [Controller\InterestedPartyController::class, 'new'],
            [Controller\InterestedPartyController::class, 'edit'],
        ],
        Entity\InternalAudit::class => [[Controller\AuditController::class, 'new'], [Controller\AuditController::class, 'edit']],
        Entity\Location::class => [[Controller\LocationController::class, 'new'], [Controller\LocationController::class, 'edit']],
        Entity\Person::class => [[Controller\PersonController::class, 'new'], [Controller\PersonController::class, 'edit']],
        Entity\Supplier::class => [[Controller\SupplierController::class, 'new'], [Controller\SupplierController::class, 'edit']],
        Entity\ThreatIntelligence::class => [
            [Controller\ThreatIntelligenceController::class, 'new'],
            [Controller\ThreatIntelligenceController::class, 'edit'],
        ],
        Entity\Training::class => [[Controller\TrainingController::class, 'new'], [Controller\TrainingController::class, 'edit']],
        Entity\CryptographicOperation::class => null,
        Entity\PhysicalAccessLog::class => null,
    ];

    /** Attributes that decide on the record itself (tenant scope) — API_EDIT covers them. */
    private const array RECORD_ATTRIBUTES = ['ROLE_USER', 'edit', 'view'];

    #[Test]
    public function everyWritableResourceOfTheGenericVoterNamesItsForm(): void
    {
        foreach ($this->genericWritableResources() as $class) {
            self::assertArrayHasKey($class, self::FORMS, sprintf(
                '%s is writable over the API but has no entry in %s::FORMS — name the controller actions of its form.',
                $class,
                self::class,
            ));
        }
        foreach (array_keys(self::FORMS) as $class) {
            self::assertContains($class, $this->genericWritableResources(), $class . ' is no longer a writable resource of ApiTenantVoter.');
        }
    }

    /** @return iterable<string, array{class-string}> */
    public static function resources(): iterable
    {
        foreach (array_keys(self::FORMS) as $class) {
            yield (new ReflectionClass($class))->getShortName() => [$class];
        }
    }

    /** @param class-string $class */
    #[Test]
    #[DataProvider('resources')]
    public function apiDemandsTheRoleOfTheForm(string $class): void
    {
        $forms = self::FORMS[$class];
        if ($forms === null) {
            // W3: machine logs are evidence, fed by an integration account — admin only.
            self::assertSame(['ROLE_ADMIN'], ApiTenantVoter::WRITE_ROLES[$class] ?? null, $class . ': a log without a form is fed by an admin-level account only.');

            return;
        }

        $expected = [];
        foreach ($forms as [$controller, $method]) {
            foreach (self::formAttributes($controller, $method) as $required) {
                $expected[] = $required === IsmsSteeringVoter::EDIT ? IsmsSteeringVoter::STEERING_ROLES : [$required];
            }
        }

        $actual = ApiTenantVoter::WRITE_ROLES[$class] ?? null;
        if ($expected === []) {
            self::assertNull($actual, sprintf('%s: the form needs only ROLE_USER, the API demands %s.', $class, json_encode($actual)));

            return;
        }
        foreach ($expected as $roles) {
            self::assertSame($roles, $actual, sprintf('%s: the form demands %s, the API %s.', $class, json_encode($roles), json_encode($actual)));
        }
    }

    /**
     * Backbone resources decided by their own entity voter on the API
     * (`is_granted('edit', object)`): the form must not demand more than that
     * same record decision — otherwise the API would let through what the form stops.
     *
     * @var array<class-string, list<array{class-string, string}>>
     */
    private const array VOTER_FORMS = [
        Entity\Asset::class => [[Controller\AssetController::class, 'new'], [Controller\AssetController::class, 'edit']],
        Entity\Risk::class => [[Controller\RiskController::class, 'new'], [Controller\RiskController::class, 'edit']],
        Entity\Incident::class => [[Controller\IncidentController::class, 'new'], [Controller\IncidentController::class, 'edit']],
        Entity\Control::class => [[Controller\StatementOfApplicabilityController::class, 'edit']],
    ];

    /** S6: every writable resource is named in one of the two maps. */
    #[Test]
    public function everyWritableResourceIsCompared(): void
    {
        foreach ($this->writableResources() as $class) {
            self::assertTrue(
                array_key_exists($class, self::FORMS) || array_key_exists($class, self::VOTER_FORMS),
                sprintf('%s is writable over the API — name its form in %s::FORMS or ::VOTER_FORMS.', $class, self::class),
            );
        }
    }

    /** @return iterable<string, array{class-string}> */
    public static function voterResources(): iterable
    {
        foreach (array_keys(self::VOTER_FORMS) as $class) {
            yield (new ReflectionClass($class))->getShortName() => [$class];
        }
    }

    /** @param class-string $class */
    #[Test]
    #[DataProvider('voterResources')]
    public function voterResourcesDemandNoMoreInTheFormThanOnTheApi(string $class): void
    {
        foreach (self::VOTER_FORMS[$class] as [$controller, $method]) {
            self::assertSame(
                [],
                self::formAttributes($controller, $method),
                sprintf('%s::%s demands more than the record voter the API uses for %s — add the role to the API operations.', $controller, $method, $class),
            );
        }
        foreach ((new ReflectionClass($class))->getAttributes(ApiResource::class)[0]->newInstance()->getOperations() ?? [] as $operation) {
            if ($operation instanceof \ApiPlatform\Metadata\Put || $operation instanceof \ApiPlatform\Metadata\Patch) {
                self::assertStringContainsString("is_granted('edit', object)", (string) $operation->getSecurity(), $class . ': the API edits through the record voter of the form.');
            }
        }
    }

    /**
     * What a form action demands beyond the record itself: #[IsGranted] on the
     * action and on its controller class, and literal denyAccessUnlessGranted()
     * calls in the action body (S6 — the method attribute alone missed both).
     *
     * @param class-string $controller
     * @return list<string>
     */
    private static function formAttributes(string $controller, string $method): array
    {
        $reflection = new ReflectionMethod($controller, $method);
        $attributes = [
            ...(new ReflectionClass($controller))->getAttributes(IsGranted::class),
            ...$reflection->getAttributes(IsGranted::class),
        ];
        $required = [];
        foreach ($attributes as $attribute) {
            $required[] = $attribute->newInstance()->attribute;
        }
        $source = implode('', array_slice(
            file((string) $reflection->getFileName()) ?: [],
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
        preg_match_all("/denyAccessUnlessGranted\\(\\s*'([A-Za-z_]+)'/", $source, $calls);
        $required = [...$required, ...$calls[1]];

        return array_values(array_unique(array_filter(
            $required,
            static fn (mixed $r): bool => is_string($r) && !in_array($r, self::RECORD_ATTRIBUTES, true),
        )));
    }

    /** @return list<class-string> */
    private function writableResources(): array
    {
        $classes = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Entity/*.php') ?: [] as $file) {
            $class = 'App\\Entity\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            $attributes = (new ReflectionClass($class))->getAttributes(ApiResource::class);
            foreach ($attributes === [] ? [] : $attributes[0]->newInstance()->getOperations() ?? [] as $operation) {
                if (!$operation instanceof Get && !$operation instanceof GetCollection) {
                    $classes[] = $class;
                    break;
                }
            }
        }

        return $classes;
    }

    /** @return list<class-string> */
    private function genericWritableResources(): array
    {
        $classes = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Entity/*.php') ?: [] as $file) {
            $class = 'App\\Entity\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            $attributes = (new ReflectionClass($class))->getAttributes(ApiResource::class);
            if ($attributes === []) {
                continue;
            }
            foreach ($attributes[0]->newInstance()->getOperations() ?? [] as $operation) {
                if ($operation instanceof Get || $operation instanceof GetCollection || $operation instanceof Delete) {
                    continue;
                }
                $expression = (string) ($operation->getSecurity() ?? '') . (string) ($operation->getSecurityPostDenormalize() ?? '');
                if (str_contains($expression, ApiTenantVoter::API_CREATE) || str_contains($expression, ApiTenantVoter::API_EDIT)) {
                    $classes[] = $class;
                    break;
                }
            }
        }

        return $classes;
    }
}
