<?php

namespace App\Form;

use App\Entity\Location;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LocationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'location.field.name',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'location.placeholder.name',
                ],
            ])
            ->add('locationType', ChoiceType::class, [
                'label' => 'location.field.location_type',
                'choices' => [
                    'location.type.building' => 'building',
                    'location.type.floor' => 'floor',
                    'location.type.room' => 'room',
                    'location.type.area' => 'area',
                    'location.type.datacenter' => 'datacenter',
                    'location.type.server_room' => 'server_room',
                    'location.type.office' => 'office',
                    'location.type.warehouse' => 'warehouse',
                    'location.type.gate' => 'gate',
                    'location.type.entrance' => 'entrance',
                    'location.type.parking' => 'parking',
                    'location.type.outdoor' => 'outdoor',
                    'location.type.other' => 'other',
                ],
                'required' => true,
                    'choice_translation_domain' => 'locations',
            ])
            ->add('code', TextType::class, [
                'label' => 'location.field.code',
                'required' => false,
                'attr' => [
                    'maxlength' => 50,
                    'placeholder' => 'location.placeholder.code',
                ],
                'help' => 'location.help.code',
            ])
            ->add('parentLocation', EntityType::class, [
                'label' => 'location.field.parent_location',
                'class' => Location::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'location.placeholder.parent_location',
                'help' => 'location.help.parent_location',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'location.field.description',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'location.placeholder.description',
                ],
            ])
            ->add('address', TextareaType::class, [
                'label' => 'location.field.address',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'location.placeholder.address',
                ],
            ])
            ->add('city', TextType::class, [
                'label' => 'location.field.city',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'location.placeholder.city',
                ],
            ])
            ->add('country', TextType::class, [
                'label' => 'location.field.country',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'location.placeholder.country',
                ],
            ])
            ->add('postalCode', TextType::class, [
                'label' => 'location.field.postal_code',
                'required' => false,
                'attr' => [
                    'maxlength' => 20,
                    'placeholder' => 'location.placeholder.postal_code',
                ],
            ])
            ->add('securityLevel', ChoiceType::class, [
                'label' => 'location.field.security_level',
                'choices' => [
                    'location.security.public' => 'public',
                    'location.security.restricted' => 'restricted',
                    'location.security.controlled' => 'controlled',
                    'location.security.secure' => 'secure',
                    'location.security.high_security' => 'high_security',
                ],
                'required' => true,
                    'choice_translation_domain' => 'locations',
            ])
            ->add('requiresBadgeAccess', CheckboxType::class, [
                'label' => 'location.field.requires_badge_access',
                'required' => false,
            ])
            ->add('requiresEscort', CheckboxType::class, [
                'label' => 'location.field.requires_escort',
                'required' => false,
            ])
            ->add('cameraMonitored', CheckboxType::class, [
                'label' => 'location.field.camera_monitored',
                'required' => false,
            ])
            ->add('accessControlSystem', TextType::class, [
                'label' => 'location.field.access_control_system',
                'required' => false,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'location.placeholder.access_control_system',
                ],
            ])
            ->add('responsiblePerson', TextType::class, [
                'label' => 'location.field.responsible_person',
                'required' => false,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'location.placeholder.responsible_person',
                ],
            ])
            ->add('capacity', IntegerType::class, [
                'label' => 'location.field.capacity',
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'placeholder' => 'location.placeholder.capacity',
                ],
                'help' => 'location.help.capacity',
            ])
            ->add('squareMeters', NumberType::class, [
                'label' => 'location.field.square_meters',
                'required' => false,
                'attr' => [
                    'step' => '0.01',
                    'min' => '0',
                    'placeholder' => 'location.placeholder.square_meters',
                ],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'location.field.active',
                'required' => false,
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'location.field.notes',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'location.placeholder.notes',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Location::class,
            'translation_domain' => 'locations',
        ]);
    }
}
