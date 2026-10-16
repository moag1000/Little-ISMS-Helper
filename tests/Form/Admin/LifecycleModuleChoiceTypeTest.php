<?php

declare(strict_types=1);

namespace App\Tests\Form\Admin;

use App\Form\Admin\LifecycleOverrideType;
use App\Form\Admin\WorkflowStepOverlayType;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

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

        return [
            new PreloadedExtension([
                new LifecycleOverrideType($modules),
                new WorkflowStepOverlayType($modules),
            ], []),
            new ValidatorExtension(Validation::createValidator()),
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
