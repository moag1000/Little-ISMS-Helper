<?php

declare(strict_types=1);

namespace App\Form;

/**
 * JsonBuilderType option sets for the structured JSON columns of
 * ProcessingActivityType (GDPR Art. 30 VVT).
 *
 * Kept out of the FormType so its size stays within the god-class ratchet
 * (Gate 25); the labels are translation keys resolved in the form's
 * `privacy` domain.
 */
final class ProcessingActivityJsonBuilderOptions
{
    /**
     * Art. 30(1)(f) — retention per data category.
     * Shape: {dataCategory: "period"} — one row per category.
     *
     * @return array<string, mixed>
     */
    public static function retentionPerCategory(): array
    {
        return [
            'label' => 'processing_activity.form.retention_per_category',
            'help' => 'processing_activity.help.retention_per_category',
            'required' => false,
            'shape' => 'map',
            'scalar_value' => true,
            'key_label' => 'processing_activity.json_builder.retention.category',
            'add_label' => 'processing_activity.json_builder.retention.add',
            'fields' => [
                [
                    'key' => 'period',
                    'type' => 'text',
                    'label' => 'processing_activity.json_builder.retention.period',
                    'required' => true,
                    'placeholder' => 'processing_activity.json_builder.retention.period_placeholder',
                ],
            ],
        ];
    }

    /**
     * GDPR Art. 26 — joint-controller arrangement, shown only when
     * isJointController = yes.
     * Shape: [{name, contact, responsibilities, arrangement_reference}].
     *
     * @return array<string, mixed>
     */
    public static function jointControllerDetails(): array
    {
        return [
            'label' => 'processing_activity.form.joint_controller_details',
            'help' => 'processing_activity.help.joint_controller_details_json',
            'required' => false,
            'attr' => [
                'data-depends-on' => 'processing_activity_isJointController',
                'data-depends-on-value' => '1',
            ],
            'shape' => 'list',
            'add_label' => 'processing_activity.json_builder.joint_controller.add',
            'fields' => [
                [
                    'key' => 'name',
                    'type' => 'text',
                    'label' => 'processing_activity.json_builder.joint_controller.name',
                    'required' => true,
                ],
                [
                    'key' => 'contact',
                    'type' => 'text',
                    'label' => 'processing_activity.json_builder.joint_controller.contact',
                ],
                [
                    'key' => 'arrangement_reference',
                    'type' => 'text',
                    'label' => 'processing_activity.json_builder.joint_controller.arrangement_reference',
                ],
                [
                    'key' => 'responsibilities',
                    'type' => 'textarea',
                    'label' => 'processing_activity.json_builder.joint_controller.responsibilities',
                ],
            ],
        ];
    }
}
