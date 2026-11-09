<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Form\SectionMapInterface;
use App\Entity\TenantBranding;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Per-tenant Report-Doc Style Configurator.
 *
 * Edits the `reportDoc*` fields on TenantBranding that drive the
 * `_fa_report_doc.html.twig` macro (watermark, exec-summary/appendix/
 * distribution-list toggles, footer disclaimer).
 */
// SectionMap replaced by fa-tabs in edit.html.twig (live-preview compat).
// Fields are grouped into General/Branding tabs.
// Preview panel is a sibling col alongside the fa-tabs container.
final class TenantReportStyleType extends AbstractType implements SectionMapInterface
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reportDocWatermarkEnabled', CheckboxType::class, [
                'label' => 'admin.report_style.field.watermark_enabled',
                'help' => 'admin.report_style.help.watermark_enabled',
                'required' => false,
                'translation_domain' => 'admin',
            ])
            ->add('reportDocShowExecSummary', CheckboxType::class, [
                'label' => 'admin.report_style.field.show_exec_summary',
                'help' => 'admin.report_style.help.show_exec_summary',
                'required' => false,
                'translation_domain' => 'admin',
            ])
            ->add('reportDocShowAppendix', CheckboxType::class, [
                'label' => 'admin.report_style.field.show_appendix',
                'help' => 'admin.report_style.help.show_appendix',
                'required' => false,
                'translation_domain' => 'admin',
            ])
            ->add('reportDocShowDistributionList', CheckboxType::class, [
                'label' => 'admin.report_style.field.show_distribution_list',
                'help' => 'admin.report_style.help.show_distribution_list',
                'required' => false,
                'translation_domain' => 'admin',
            ])
            ->add('reportDocFooterDisclaimer', TextareaType::class, [
                'label' => 'admin.report_style.field.footer_disclaimer',
                'help' => 'admin.report_style.help.footer_disclaimer',
                'required' => false,
                'translation_domain' => 'admin',
                'attr' => ['rows' => 3, 'maxlength' => 1000],
                'constraints' => [new Assert\Length(max: 1000)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TenantBranding::class,
            'translation_domain' => 'admin',
            'attr' => ['data-controller' => 'report-style-preview'],
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function getSectionMap(): array
    {
        return [
            'branding'   => ['reportDocWatermarkEnabled'],
            'content'    => ['reportDocShowExecSummary', 'reportDocShowAppendix', 'reportDocShowDistributionList', 'reportDocFooterDisclaimer'],
        ];
    }
}
