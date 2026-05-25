<?php

declare(strict_types=1);

namespace App\Form\Entry;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Single Indicator-of-Compromise row inside ThreatIntelligence.iocsList.
 *
 * Backs the STIX 2.1 shape:
 *   {type: ip|domain|hash|url|email, value: string, confidence: 1..5}
 *
 * Wired via CollectionType in ThreatIntelligenceType — `data_class => null`
 * keeps the entity column as a plain associative array so legacy rows with
 * the historic `context` key still round-trip cleanly through Doctrine
 * (the extra key is preserved by Symfony's PropertyAccess + array merge,
 * only re-emitted on the next form submit).
 *
 * S5 Bucket 5 — replaces JsonStructuredType freetext-JSON editing.
 */
final class IocEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'threat_intelligence.ioc.field.type',
                'required' => false,
                'placeholder' => 'threat_intelligence.ioc.placeholder.type',
                'choices' => [
                    'threat_intelligence.ioc.type.ip' => 'ip',
                    'threat_intelligence.ioc.type.domain' => 'domain',
                    'threat_intelligence.ioc.type.hash' => 'hash',
                    'threat_intelligence.ioc.type.url' => 'url',
                    'threat_intelligence.ioc.type.email' => 'email',
                ],
                'attr' => ['class' => 'form-select form-select-sm'],
            ])
            ->add('value', TextType::class, [
                'label' => 'threat_intelligence.ioc.field.value',
                'required' => false,
                'attr' => [
                    'maxlength' => 500,
                    'placeholder' => 'threat_intelligence.ioc.placeholder.value',
                    'class' => 'form-control form-control-sm',
                ],
            ])
            ->add('confidence', ChoiceType::class, [
                'label' => 'threat_intelligence.ioc.field.confidence',
                'required' => false,
                'placeholder' => 'threat_intelligence.ioc.placeholder.confidence',
                'choices' => [
                    'threat_intelligence.ioc.confidence.1' => 1,
                    'threat_intelligence.ioc.confidence.2' => 2,
                    'threat_intelligence.ioc.confidence.3' => 3,
                    'threat_intelligence.ioc.confidence.4' => 4,
                    'threat_intelligence.ioc.confidence.5' => 5,
                ],
                'attr' => ['class' => 'form-select form-select-sm'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'translation_domain' => 'threat',
            'empty_data' => static fn (): array => [
                'type' => null,
                'value' => '',
                'confidence' => null,
            ],
        ]);
    }
}
