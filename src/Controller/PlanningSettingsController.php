<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\Tenant;
use App\Repository\PlanningSettingsRepository;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Per-tenant resource-planning settings (Engineering-Spec §8).
 */
final class PlanningSettingsController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly PlanningSettingsRepository $settingsRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ModuleConfigurationService $moduleService,
    ) {
    }

    #[Route('/planning/settings', name: 'app_planning_settings', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function edit(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('resource_planning')) {
            return $redirect;
        }

        $tenant = $this->security->getUser()?->getTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createAccessDeniedException();
        }

        $settings = $this->settingsRepository->getOrCreate($tenant);

        $form = $this->createFormBuilder([
                'defaultRecurrenceMonths' => $settings->getDefaultRecurrenceMonths(),
                'roadmapHorizonWeeks' => $settings->getRoadmapHorizonWeeks(),
                'overbookingThresholdPct' => $settings->getOverbookingThresholdPct(),
                'fullTimeHoursPerWeek' => $settings->getFullTimeHoursPerWeek() ?? 40.0,
                'hoursPerDay' => $settings->getHoursPerDay() ?? 8.0,
                'scopes' => implode("\n", $settings->getScopes()),
            ], ['translation_domain' => 'planning'])
            ->add('defaultRecurrenceMonths', IntegerType::class, [
                'label' => 'planning.settings.field.default_recurrence_months',
                'attr' => ['min' => 1],
            ])
            ->add('roadmapHorizonWeeks', IntegerType::class, [
                'label' => 'planning.settings.field.roadmap_horizon_weeks',
                'attr' => ['min' => 1, 'max' => 52],
            ])
            ->add('overbookingThresholdPct', IntegerType::class, [
                'label' => 'planning.settings.field.overbooking_threshold_pct',
                'attr' => ['min' => 1, 'max' => 500],
            ])
            ->add('fullTimeHoursPerWeek', NumberType::class, [
                'label' => 'planning.settings.field.full_time_hours_per_week',
                'scale' => 1,
                'attr' => ['min' => 1, 'step' => 0.5],
                'constraints' => [new Assert\Positive()],
            ])
            ->add('hoursPerDay', NumberType::class, [
                'label' => 'planning.settings.field.hours_per_day',
                'scale' => 1,
                'attr' => ['min' => 1, 'step' => 0.5],
                'constraints' => [new Assert\Positive()],
            ])
            ->add('scopes', TextareaType::class, [
                'label' => 'planning.settings.field.scopes',
                'required' => false,
                'mapped' => false,
                'attr' => ['rows' => 5],
                'help' => 'planning.settings.help.scopes',
            ])
            ->add('save', SubmitType::class, ['label' => 'common.save'])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $settings->setDefaultRecurrenceMonths((int) $data['defaultRecurrenceMonths']);
            $settings->setRoadmapHorizonWeeks((int) $data['roadmapHorizonWeeks']);
            $settings->setOverbookingThresholdPct((int) $data['overbookingThresholdPct']);
            $settings->setFullTimeHoursPerWeek($data['fullTimeHoursPerWeek'] !== null ? (float) $data['fullTimeHoursPerWeek'] : null);
            $settings->setHoursPerDay($data['hoursPerDay'] !== null ? (float) $data['hoursPerDay'] : null);

            $scopes = array_values(array_filter(array_map(
                static fn (string $l): string => trim($l),
                preg_split('/\r\n|\r|\n/', (string) $form->get('scopes')->getData()) ?: [],
            ), static fn (string $l): bool => $l !== ''));
            $settings->setScopes($scopes);

            if ($settings->getId() === null) {
                $this->entityManager->persist($settings);
            }
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('planning.settings.success.saved', [], 'planning'));

            return $this->redirectToRoute('app_planning_settings');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('planning/settings/edit.html.twig', ['form' => $form], new Response(status: $status));
    }
}
