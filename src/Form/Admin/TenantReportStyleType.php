<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\TenantBranding;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Per-tenant Report-Doc Style Configurator.
 *
 * Edits the 12 `reportDoc*` fields on TenantBranding that drive the
 * `_fa_report_doc.html.twig` macro (cover pattern, default audience,
 * watermark, exec-summary/appendix/distribution-list toggles, font
 * family, page orientation, chart color scheme, footer disclaimer,
 * optional custom-CSS override).
 *
 * Custom-CSS field is only rendered for ROLE_ADMIN. Watermark opacity
 * is exposed as 0–100 % via the `report_style_preview` Stimulus
 * controller and persisted as a 0.0–1.0 float.
 */
final class TenantReportStyleType extends AbstractType
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reportDocCoverPattern', ChoiceType::class, [
                'label' => 'admin.report_style.field.cover_pattern',
                'help' => 'admin.report_style.help.cover_pattern',
                'choices' => [
                    'admin.report_style.cover.minimal' => 'minimal',
                    'admin.report_style.cover.branded' => 'branded',
                    'admin.report_style.cover.board_formal' => 'board-formal',
                    'admin.report_style.cover.auditor_formal' => 'auditor-formal',
                ],
                'required' => true,
                'translation_domain' => 'admin',
                'choice_translation_domain' => 'admin',
            ])
            ->add('reportDocDefaultAudience', ChoiceType::class, [
                'label' => 'admin.report_style.field.default_audience',
                'help' => 'admin.report_style.help.default_audience',
                'choices' => [
                    'admin.report_style.audience.vorstand' => 'vorstand',
                    'admin.report_style.audience.auditor' => 'auditor',
                    'admin.report_style.audience.aufsicht' => 'aufsicht',
                    'admin.report_style.audience.internal' => 'internal',
                ],
                'required' => true,
                'translation_domain' => 'admin',
                'choice_translation_domain' => 'admin',
            ])
            ->add('reportDocFontFamily', ChoiceType::class, [
                'label' => 'admin.report_style.field.font_family',
                'help' => 'admin.report_style.help.font_family',
                'choices' => [
                    'Inter' => 'Inter',
                    'Roboto' => 'Roboto',
                    'Source Sans 3' => 'Source Sans 3',
                    'Lato' => 'Lato',
                    'Open Sans' => 'Open Sans',
                    'Merriweather (Serif)' => 'Merriweather',
                    'IBM Plex Sans' => 'IBM Plex Sans',
                    'System UI' => 'system-ui',
                ],
                'required' => true,
                'translation_domain' => 'admin',
            ])
            ->add('reportDocPageOrientation', ChoiceType::class, [
                'label' => 'admin.report_style.field.page_orientation',
                'help' => 'admin.report_style.help.page_orientation',
                'choices' => [
                    'admin.report_style.orientation.portrait' => 'portrait',
                    'admin.report_style.orientation.landscape' => 'landscape',
                    'admin.report_style.orientation.auto' => 'auto',
                ],
                'required' => true,
                'translation_domain' => 'admin',
                'choice_translation_domain' => 'admin',
            ])
            ->add('reportDocChartColorScheme', ChoiceType::class, [
                'label' => 'admin.report_style.field.chart_color_scheme',
                'help' => 'admin.report_style.help.chart_color_scheme',
                'choices' => [
                    'admin.report_style.chart.aurora' => 'aurora',
                    'admin.report_style.chart.audit' => 'audit',
                    'admin.report_style.chart.print_friendly' => 'print-friendly',
                    'admin.report_style.chart.colorblind_safe' => 'colorblind-safe',
                ],
                'required' => true,
                'translation_domain' => 'admin',
                'choice_translation_domain' => 'admin',
                'attr' => [
                    'data-report-style-preview-target' => 'chartScheme',
                ],
            ])
            ->add('reportDocWatermarkEnabled', CheckboxType::class, [
                'label' => 'admin.report_style.field.watermark_enabled',
                'help' => 'admin.report_style.help.watermark_enabled',
                'required' => false,
                'translation_domain' => 'admin',
            ])
            ->add('reportDocWatermarkOpacity', NumberType::class, [
                'label' => 'admin.report_style.field.watermark_opacity',
                'help' => 'admin.report_style.help.watermark_opacity',
                'required' => true,
                'scale' => 2,
                'translation_domain' => 'admin',
                'attr' => [
                    'min' => 0,
                    'max' => 1,
                    'step' => 0.01,
                    'data-report-style-preview-target' => 'opacity',
                ],
                'constraints' => [
                    new Assert\Range(min: 0.0, max: 1.0),
                ],
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

        // Advanced: full CSS override — gated to ROLE_ADMIN to limit
        // blast-radius (paste-only, rendered |raw inside the report
        // doc style).
        if ($this->security->isGranted('ROLE_ADMIN')) {
            $builder->add('reportDocCustomCss', TextareaType::class, [
                'label' => 'admin.report_style.field.custom_css',
                'help' => 'admin.report_style.help.custom_css',
                'required' => false,
                'translation_domain' => 'admin',
                'attr' => ['rows' => 8, 'spellcheck' => 'false', 'class' => 'font-monospace'],
                'constraints' => [new Assert\Length(max: 16000)],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TenantBranding::class,
            'translation_domain' => 'admin',
            'attr' => ['data-controller' => 'report-style-preview'],
        ]);
    }
}
