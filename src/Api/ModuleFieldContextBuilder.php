<?php

declare(strict_types=1);

namespace App\Api;

use ApiPlatform\State\SerializerContextBuilderInterface;
use App\Attribute\ModuleField;
use App\Service\ModuleConfigurationService;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * Fields of an optional module ({@see ModuleField}) are neither writable nor
 * readable over REST or the assistant (MCP sub-requests) while that module
 * is off — the same as the web form and the detail page, which do not show
 * them then. The serializer ignores them in the request body and leaves them
 * out of the answer, like any other field outside the resource's groups
 * (S24: FAIR euro values, GDPR flags and NIS-2/DORA reporting fields stayed
 * readable after a module was switched off). Filters and sorting on them
 * are refused by {@see \App\EventListener\ApiModuleFieldFilterListener}.
 */
#[AsDecorator('api_platform.serializer.context_builder')]
final readonly class ModuleFieldContextBuilder implements SerializerContextBuilderInterface
{
    public function __construct(
        #[AutowireDecorated]
        private SerializerContextBuilderInterface $inner,
        private ModuleConfigurationService $modules,
    ) {
    }

    public function createFromRequest(Request $request, bool $normalization, ?array $extractedAttributes = null): array
    {
        $context = $this->inner->createFromRequest($request, $normalization, $extractedAttributes);
        $class = $context['resource_class'] ?? null;
        if (!is_string($class) || !class_exists($class)) {
            return $context;
        }

        $inactive = $this->inactiveModuleFields($class);
        if ($inactive !== []) {
            $context[AbstractNormalizer::IGNORED_ATTRIBUTES] = array_values(array_unique([
                ...($context[AbstractNormalizer::IGNORED_ATTRIBUTES] ?? []),
                ...$inactive,
            ]));
        }

        return $context;
    }

    /**
     * @param class-string $class
     * @return list<string> property names whose module is off
     */
    public function inactiveModuleFields(string $class): array
    {
        $fields = [];
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            foreach ($property->getAttributes(ModuleField::class) as $attribute) {
                if (!$this->modules->isModuleActive($attribute->newInstance()->module)) {
                    $fields[] = $property->getName();
                }
            }
        }

        return $fields;
    }
}
