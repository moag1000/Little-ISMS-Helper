<?php

declare(strict_types=1);

namespace App\Tests\Security;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Risk;
use App\Security\FieldVisibilityResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F7 — guards that the REST API (API Platform) strips the SAME sensitive Risk
 * fields that the Twig layer (FieldVisibilityResolver) gates. Without this the
 * field-level RBAC is bypassable via `GET /api/risks/{id}` (the originally
 * shipped gap). Deterministic + DB-free: asserts the `#[ApiProperty(security:)]`
 * metadata exists and stays in parity with FIELD_ROLE_MAP.
 */
final class RiskApiFieldGateTest extends TestCase
{
    /** FIELD_ROLE_MAP key → entity member (property or getter) carrying the gate. */
    private const array KEY_TO_MEMBER = [
        'owner'         => 'riskOwner',
        'ownerPerson'   => 'riskOwnerPerson',
        'ownerDeputies' => 'riskOwnerDeputyPersons',
        'sle'           => 'singleLossExpectancy',
        'aro'           => 'annualRateOfOccurrence',
        'ale'           => 'getAnnualLossExpectancy',
    ];

    private static function securityExpressionFor(string $member): ?string
    {
        $ref = new ReflectionClass(Risk::class);
        $target = str_starts_with($member, 'get') && $ref->hasMethod($member)
            ? $ref->getMethod($member)
            : $ref->getProperty($member);

        $attrs = $target->getAttributes(ApiProperty::class);
        if ($attrs === []) {
            return null;
        }

        /** @var ApiProperty $api */
        $api = $attrs[0]->newInstance();

        return $api->getSecurity();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sensitiveFieldProvider(): iterable
    {
        foreach (self::KEY_TO_MEMBER as $key => $member) {
            yield $key => [$key, $member];
        }
    }

    #[Test]
    #[DataProvider('sensitiveFieldProvider')]
    public function every_sensitive_field_is_role_gated_on_the_api(string $key, string $member): void
    {
        $security = self::securityExpressionFor($member);

        self::assertNotNull(
            $security,
            sprintf('Risk::$%s (FIELD_ROLE_MAP "%s") must carry #[ApiProperty(security:)] — else field-RBAC is API-bypassable.', $member, $key),
        );
        self::assertStringContainsString(
            'ROLE_MANAGER',
            (string) $security,
            sprintf('Risk::$%s API gate must require ROLE_MANAGER to match the Twig gate.', $member),
        );
    }

    #[Test]
    public function api_gate_is_in_parity_with_field_role_map(): void
    {
        // Every Risk key in the Twig FIELD_ROLE_MAP must have an API counterpart here,
        // so adding a new sensitive Twig field forces adding the API gate too.
        $twigKeys = array_keys(FieldVisibilityResolver::getFieldRoleMap()['Risk'] ?? []);

        sort($twigKeys);
        $apiKeys = array_keys(self::KEY_TO_MEMBER);
        sort($apiKeys);

        self::assertSame(
            $twigKeys,
            $apiKeys,
            'FIELD_ROLE_MAP[Risk] and the API gate set drifted — every Twig-gated field needs an #[ApiProperty(security:)] gate.',
        );
    }
}
