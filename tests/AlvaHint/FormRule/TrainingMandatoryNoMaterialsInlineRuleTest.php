<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\TrainingMandatoryNoMaterialsInlineRule;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TrainingMandatoryNoMaterialsInlineRuleTest extends TestCase
{
    private TrainingMandatoryNoMaterialsInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new TrainingMandatoryNoMaterialsInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsMandatoryWithoutMaterialsOrFiles(): void
    {
        self::assertTrue($this->rule->supports(['mandatory' => '1'], $this->user));
        self::assertTrue($this->rule->supports([
            'mandatory' => true,
            'materials' => '',
            'materialFiles' => [],
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenNotMandatory(): void
    {
        self::assertFalse($this->rule->supports(['mandatory' => '0'], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenMaterialsTextProvided(): void
    {
        self::assertFalse($this->rule->supports([
            'mandatory' => '1',
            'materials' => 'Slide deck attached',
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenMaterialFilesProvided(): void
    {
        self::assertFalse($this->rule->supports([
            'mandatory' => '1',
            'materialFiles' => ['training.pdf'],
        ], $this->user));
    }

    #[Test]
    public function evaluateAnchorsOnMaterialsField(): void
    {
        $hint = $this->rule->evaluate(['mandatory' => '1'], $this->user);

        self::assertSame('training.form.mandatory_without_materials', $hint->key);
        self::assertSame('materials', $hint->field);
    }

    #[Test]
    public function entityTypeAndModulesAreCorrect(): void
    {
        self::assertSame('training', $this->rule->entityType());
        self::assertSame(['training'], $this->rule->requiredModules());
    }
}
