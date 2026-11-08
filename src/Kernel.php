<?php

declare(strict_types=1);

namespace App;

use App\Risk\RiskMatrixThresholds;
use App\Risk\RiskScaleProvider;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();

        // The risk bands are the organisation's (ISO 27001 6.1.2 a): every
        // RiskMatrixThresholds::classify() reads them from the tenant in scope.
        $container = $this->getContainer();
        RiskMatrixThresholds::useProvider(static function () use ($container): ?array {
            if (!$container->has(RiskScaleProvider::class)) {
                return null;
            }
            $provider = $container->get(RiskScaleProvider::class);

            return $provider instanceof RiskScaleProvider ? $provider->currentEdges() : null;
        });
    }

    public function shutdown(): void
    {
        RiskMatrixThresholds::useProvider(null);
        parent::shutdown();
    }
}
