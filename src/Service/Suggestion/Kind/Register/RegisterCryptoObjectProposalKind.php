<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind\Register;

use App\Enum\RegisterType;

/** Crypto inventory (A.8.24) from the key-operation log and the cloud services in use. */
final class RegisterCryptoObjectProposalKind extends AbstractRegisterProposalKind
{
    protected function type(): RegisterType
    {
        return RegisterType::CRYPTO_OBJECT;
    }
}
