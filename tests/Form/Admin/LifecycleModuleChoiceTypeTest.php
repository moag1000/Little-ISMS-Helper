<?php

declare(strict_types=1);

namespace App\Tests\Form\Admin;

use App\Form\Admin\LifecycleOverrideType;
use App\Form\Admin\WorkflowStepOverlayType;
use App\Service\ModuleConfigurationService;
use App\Validator\Constraint\JsonShapeValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The module override in both admin overlay forms only accepts keys from
 * config/modules.yaml. A free-text key that names no module would make
 * ModuleGateGuard block the transition/step permanently.
 */
final class LifecycleModuleChoiceTypeTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        $modules = new ModuleConfigurationService(\dirname(__DIR__, 3));
        // WorkflowStepOverlayType also carries a JsonBuilderType field whose
        // JsonShape validator needs the translator; wire it explicitly.
        $validators = new ConstraintValidatorFactory([
            JsonShapeValidator::class => new JsonShapeValidator($this->createStub(TranslatorInterface::class)),
        ]);

        return [
            new PreloadedExtension([
                new LifecycleOverrideType($modules),
                new WorkflowStepOverlayType($modules),
            ], []),
            new ValidatorExtension(
                Validation::createValidatorBuilder()->setConstraintValidatorFactory($validators)->getValidator(),
            ),
        ];
    }

    /** @return iterable<string, array{class-string}> */
    public static function formTypes(): iterable
    {
        yield 'lifecycle override' => [LifecycleOverrideType::class];
        yield 'workflow step overlay' => [WorkflowStepOverlayType::class];
    }

    #[Test]
    #[DataProvider('formTypes')]
    public function unknownModuleKeyIsRejected(string $type): void
    {
        $form = $this->factory->create($type);
        $form->submit(['module' => 'threat']);

        self::assertFalse($form->get('module')->isValid());
    }

    #[Test]
    #[DataProvider('formTypes')]
    public function knownModuleKeyIsAccepted(string $type): void
    {
        $form = $this->factory->create($type);
        $form->submit(['module' => 'vulnerability_intel']);

        self::assertTrue($form->get('module')->isValid());
        self::assertSame('vulnerability_intel', $form->get('module')->getData());
    }

    #[Test]
    #[DataProvider('formTypes')]
    public function emptyModuleMeansNoGate(string $type): void
    {
        $form = $this->factory->create($type);
        $form->submit(['module' => '']);

        self::assertTrue($form->get('module')->isValid());
        self::assertNull($form->get('module')->getData());
    }
}
