<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Incident;
use App\Entity\RiskIncidentLink;
use App\Service\Risk\RiskIncidentLinkService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Inline form rendered on risk-show and incident-show pages to create a
 * structured cross-link between a Risk and an Incident.
 *
 * Translation domain: 'risk' (link-related keys under risk.link_incident.*)
 */
class RiskIncidentLinkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('incident', EntityType::class, [
                'label'         => 'risk.link_incident.field.incident',
                'class'         => Incident::class,
                'choice_label'  => function (Incident $incident): string {
                    return sprintf('#%s — %s', $incident->getIncidentNumber() ?? '?', $incident->getTitle() ?? '');
                },
                'placeholder'   => 'risk.link_incident.placeholder.incident',
                'required'      => true,
                'attr'          => [
                    'data-controller' => 'tom-select',
                ],
            ])
            ->add('linkType', ChoiceType::class, [
                'label'    => 'risk.link_incident.field.link_type',
                'required' => true,
                'choices'  => array_combine(
                    array_map(
                        static fn (string $t): string => 'risk.link_incident.link_type.' . $t,
                        RiskIncidentLinkService::VALID_LINK_TYPES
                    ),
                    RiskIncidentLinkService::VALID_LINK_TYPES
                ),
                'choice_translation_domain' => 'risk',
            ])
            ->add('notes', TextareaType::class, [
                'label'    => 'risk.link_incident.field.notes',
                'required' => false,
                'attr'     => [
                    'rows'        => 3,
                    'placeholder' => 'risk.link_incident.placeholder.notes',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => RiskIncidentLink::class,
            'translation_domain' => 'risk',
        ]);
    }
}
