<?php

namespace App\Form;

use App\Entity\ScheduledReport;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ScheduledReportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'scheduled_report.form.name',
                'attr' => ['placeholder' => 'scheduled_report.form.name_placeholder'],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(['min' => 3, 'max' => 255]),
                ],
            ])
            ->add('reportType', ChoiceType::class, [
                'label' => 'scheduled_report.form.report_type',
                'choices' => array_flip(ScheduledReport::getReportTypes()),
                'placeholder' => 'scheduled_report.form.select_type',
            ])
            ->add('schedule', ChoiceType::class, [
                'label' => 'scheduled_report.form.schedule',
                'choices' => [
                    'scheduled_report.schedule.daily' => ScheduledReport::SCHEDULE_DAILY,
                    'scheduled_report.schedule.weekly' => ScheduledReport::SCHEDULE_WEEKLY,
                    'scheduled_report.schedule.monthly' => ScheduledReport::SCHEDULE_MONTHLY,
                ],
            ])
            ->add('format', ChoiceType::class, [
                'label' => 'scheduled_report.form.format',
                'choices' => [
                    'PDF' => ScheduledReport::FORMAT_PDF,
                    'Excel' => ScheduledReport::FORMAT_EXCEL,
                ],
                'expanded' => true,
            ])
            ->add('preferredTime', TimeType::class, [
                'label' => 'scheduled_report.form.preferred_time',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'scheduled_report.form.preferred_time_help',
            ])
            ->add('dayOfWeek', ChoiceType::class, [
                'label' => 'scheduled_report.form.day_of_week',
                'choices' => array_flip(ScheduledReport::getDaysOfWeek()),
                'required' => false,
                'placeholder' => 'scheduled_report.form.select_day',
                'help' => 'scheduled_report.form.day_of_week_help',
            ])
            ->add('dayOfMonth', ChoiceType::class, [
                'label' => 'scheduled_report.form.day_of_month',
                'choices' => array_combine(range(1, 28), range(1, 28)),
                'required' => false,
                'placeholder' => 'scheduled_report.form.select_day',
                'help' => 'scheduled_report.form.day_of_month_help',
            ])
            ->add('recipientsText', TextareaType::class, [
                'label' => 'scheduled_report.form.recipients',
                'mapped' => false,
                'required' => true,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'scheduled_report.form.recipients_placeholder',
                ],
                'help' => 'scheduled_report.form.recipients_help',
                'constraints' => [
                    new Assert\NotBlank(),
                ],
            ])
            ->add('locale', ChoiceType::class, [
                'label' => 'scheduled_report.form.locale',
                'choices' => [
                    'Deutsch' => 'de',
                    'English' => 'en',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'scheduled_report.form.description',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);

        // Pre-populate recipientsText from recipients array
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $report = $event->getData();
            $form = $event->getForm();

            if ($report instanceof ScheduledReport) {
                $recipients = $report->getRecipients();
                $form->get('recipientsText')->setData(implode("\n", $recipients));
            }
        });

        // Parse recipientsText to recipients array on submit
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $report = $event->getData();
            $form = $event->getForm();

            if ($report instanceof ScheduledReport) {
                $recipientsText = $form->get('recipientsText')->getData();
                $recipients = array_filter(
                    array_map('trim', preg_split('/[\n,;]+/', $recipientsText)),
                    fn($email) => filter_var($email, FILTER_VALIDATE_EMAIL)
                );
                $report->setRecipients(array_values($recipients));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ScheduledReport::class,
            'translation_domain' => 'scheduled_reports',
        ]);
    }
}
