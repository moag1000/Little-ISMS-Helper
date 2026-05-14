<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\WorkflowStepLabelExtension;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Persona-Walkthrough Risk-Owner-Business (Task #124, KRITISCH).
 *
 * Verifies the workflow_step_label / workflow_step_description filters
 * humanise the snake_case step names emitted by SeedPolicyApprovalWorkflowCommand
 * + GenerateRegulatoryWorkflowsCommand into the German plain-language labels
 * shown in the approval UI and approver emails.
 */
#[AllowMockObjectsWithoutExpectations]
final class WorkflowStepLabelExtensionTest extends TestCase
{
    #[Test]
    public function labelReturnsTranslatedValueWhenKeyExists(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())
            ->method('trans')
            ->with('workflow.step_label.ciso_review', [], 'workflows')
            ->willReturn('Pruefung durch CISO');

        $extension = new WorkflowStepLabelExtension($translator);
        $this->assertSame('Pruefung durch CISO', $extension->label('ciso_review'));
    }

    #[Test]
    public function labelFallsBackToHumanisedSnakeCaseWhenKeyMissing(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $extension = new WorkflowStepLabelExtension($translator);

        // Translator returns the key itself → fallback humanise() kicks in.
        $this->assertSame('Brand New Step', $extension->label('brand_new_step'));
    }

    #[Test]
    public function labelReturnsEmptyForNullOrEmptyInput(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->never())->method('trans');

        $extension = new WorkflowStepLabelExtension($translator);
        $this->assertSame('', $extension->label(null));
        $this->assertSame('', $extension->label(''));
    }

    #[Test]
    public function descriptionReturnsTranslatedValueWhenKeyExists(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())
            ->method('trans')
            ->with('workflow.step_description.top_mgmt_signoff', [], 'workflows')
            ->willReturn('Die Geschaeftsleitung erteilt die formale Freigabe.');

        $extension = new WorkflowStepLabelExtension($translator);
        $this->assertSame(
            'Die Geschaeftsleitung erteilt die formale Freigabe.',
            $extension->description('top_mgmt_signoff'),
        );
    }

    #[Test]
    public function descriptionReturnsNullWhenKeyMissing(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $extension = new WorkflowStepLabelExtension($translator);
        $this->assertNull($extension->description('unknown_step'));
    }

    #[Test]
    public function descriptionReturnsNullForNullOrEmptyInput(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->never())->method('trans');

        $extension = new WorkflowStepLabelExtension($translator);
        $this->assertNull($extension->description(null));
        $this->assertNull($extension->description(''));
    }

    #[Test]
    public function filtersAreRegisteredWithCorrectNames(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $extension = new WorkflowStepLabelExtension($translator);

        $names = array_map(static fn ($f) => $f->getName(), $extension->getFilters());
        $this->assertContains('workflow_step_label', $names);
        $this->assertContains('workflow_step_description', $names);
    }
}
