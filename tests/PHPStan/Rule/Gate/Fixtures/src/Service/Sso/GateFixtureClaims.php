<?php

declare(strict_types=1);

namespace App\Service\Sso;

/** Gate 93 fixture (claim merging in src/Service/Sso): every `expect:` line is a mutation the rule must report. */
final class GateFixtureClaims
{
    private const SKEW = 60;

    /** @return array<string, mixed> */
    public function combine(array $idTokenClaims, array $userInfo, array $ui, array $id, string $now): array
    {
        $a = array_merge($idTokenClaims, $userInfo); // expect: gate93.claimMerge
        $b = array_replace($idTokenClaims, $userInfo); // expect: gate93.claimMerge
        $c = [...$idTokenClaims, ...$userInfo]; // expect: gate93.claimMerge
        $d = $userInfo + $idTokenClaims; // expect: gate93.claimMerge
        $e = $ui + $id; // expect: gate93.claimMerge
        $roles = array_values(array_unique([...$c, 'ROLE_USER']));
        $leeway = (int) $now + self::SKEW;

        return SsoClaimPolicy::mergeUserInfo($idTokenClaims, $userInfo) + [] ; // expect: gate93.claimMerge
    }
}
