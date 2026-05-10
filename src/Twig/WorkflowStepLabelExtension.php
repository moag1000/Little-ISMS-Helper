<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Humanises snake_case workflow-step names emitted by the regulatory
 * workflow seed commands ({@see \App\Command\SeedPolicyApprovalWorkflowCommand},
 * {@see \App\Command\GenerateRegulatoryWorkflowsCommand}) into plain-language
 * labels and descriptions for the approval-screen and approver-emails.
 *
 * Persona-Walkthrough Risk-Owner-Business (Task #124, KRITISCH) — German
 * Fachbereichsleiter:in saw raw `ciso_review` / `top_mgmt_signoff` step
 * identifiers in the approval UI. Without humanisation, the approver could
 * not tell what the step actually meant. This filter reads `workflow.step_label.*`
 * and `workflow.step_description.*` from the `workflows` translation domain
 * and falls back to a humanised version of the snake_case key when no
 * translation exists (so brand-new step names emit a sensible default).
 *
 * Twig usage:
 *   {{ step.name|workflow_step_label }}
 *   {{ step.name|workflow_step_description }}
 */
final class WorkflowStepLabelExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('workflow_step_label', $this->label(...)),
            new TwigFilter('workflow_step_description', $this->description(...)),
        ];
    }

    public function label(?string $stepName): string
    {
        if ($stepName === null || $stepName === '') {
            return '';
        }
        $key = 'workflow.step_label.' . $stepName;
        $translated = $this->translator->trans($key, [], 'workflows');
        if ($translated !== $key) {
            return $translated;
        }
        return $this->humanise($stepName);
    }

    public function description(?string $stepName): ?string
    {
        if ($stepName === null || $stepName === '') {
            return null;
        }
        $key = 'workflow.step_description.' . $stepName;
        $translated = $this->translator->trans($key, [], 'workflows');
        if ($translated !== $key) {
            return $translated;
        }
        return null;
    }

    private function humanise(string $snake): string
    {
        return ucwords(str_replace('_', ' ', $snake));
    }
}
