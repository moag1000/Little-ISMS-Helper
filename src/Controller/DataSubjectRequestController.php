<?php

namespace App\Controller;

use RuntimeException;
use App\Entity\DataSubjectRequest;
use App\Form\DataSubjectRequestType;
use App\Service\DataSubjectRequestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/data-subject-request', name: 'app_data_subject_request_')]
#[IsGranted('ROLE_USER')]
class DataSubjectRequestController extends AbstractController
{
    public function __construct(
        private readonly DataSubjectRequestService $dataSubjectRequestService,
    ) {
    }

    /**
     * List all data subject requests with filtering
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filterStatus = $request->query->get('status');
        $filterType = $request->query->get('type');

        if ($filterStatus === 'overdue') {
            $requests = $this->dataSubjectRequestService->findOverdue();
        } elseif ($filterStatus !== null && $filterStatus !== '') {
            $requests = $this->dataSubjectRequestService->findByStatus($filterStatus);
        } else {
            $requests = $this->dataSubjectRequestService->findAll();
        }

        // Apply type filter in-memory if set
        if ($filterType !== null && $filterType !== '') {
            $requests = array_filter(
                $requests,
                fn(DataSubjectRequest $r): bool => $r->getRequestType() === $filterType
            );
        }

        $statistics = $this->dataSubjectRequestService->getStatistics();

        return $this->render('data_subject_request/index.html.twig', [
            'requests' => $requests,
            'statistics' => $statistics,
            'current_status' => $filterStatus,
            'current_type' => $filterType,
        ]);
    }

    /**
     * Create a new data subject request
     */
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $dsr = new DataSubjectRequest();

        $form = $this->createForm(DataSubjectRequestType::class, $dsr);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->dataSubjectRequestService->create($dsr);

            $this->addFlash('success', 'dsr.flash.created');

            return $this->redirectToRoute('app_data_subject_request_show', ['id' => $dsr->getId()]);
        }

        return $this->render('data_subject_request/new.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Show data subject request details
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(DataSubjectRequest $dsr): Response
    {
        return $this->render('data_subject_request/show.html.twig', [
            'dsr' => $dsr,
        ]);
    }

    /**
     * Edit a data subject request
     */
    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, DataSubjectRequest $dsr): Response
    {
        if (in_array($dsr->getStatus(), ['completed', 'rejected'], true)) {
            $this->addFlash('error', 'dsr.flash.cannot_edit_terminal');
            return $this->redirectToRoute('app_data_subject_request_show', ['id' => $dsr->getId()]);
        }

        $form = $this->createForm(DataSubjectRequestType::class, $dsr);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->dataSubjectRequestService->update($dsr);

            $this->addFlash('success', 'dsr.flash.updated');

            return $this->redirectToRoute('app_data_subject_request_show', ['id' => $dsr->getId()]);
        }

        return $this->render('data_subject_request/edit.html.twig', [
            'dsr' => $dsr,
            'form' => $form,
        ]);
    }

    /**
     * Mark request as completed
     */
    #[Route('/{id}/complete', name: 'complete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function complete(Request $request, DataSubjectRequest $dsr): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('complete' . $dsr->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_subject_request_show', ['id' => $dsr->getId()]);
        }

        $responseDescription = $request->request->get('response_description', '');
        if ($responseDescription === '') {
            $this->addFlash('error', 'dsr.flash.response_required');
            return $this->redirectToRoute('app_data_subject_request_show', ['id' => $dsr->getId()]);
        }

        try {
            $this->dataSubjectRequestService->complete($dsr, $responseDescription);
            $this->addFlash('success', 'dsr.flash.completed');
        } catch (RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_data_subject_request_show', ['id' => $dsr->getId()]);
    }

    /**
     * Reject a request (Art. 12(5): manifestly unfounded or excessive)
     */
    #[Route('/{id}/reject', name: 'reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(Request $request, DataSubjectRequest $dsr): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('reject' . $dsr->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_subject_request_show', ['id' => $dsr->getId()]);
        }

        $rejectionReason = $request->request->get('rejection_reason', '');
        if ($rejectionReason === '') {
            $this->addFlash('error', 'dsr.flash.rejection_reason_required');
            return $this->redirectToRoute('app_data_subject_request_show', ['id' => $dsr->getId()]);
        }

        try {
            $this->dataSubjectRequestService->reject($dsr, $rejectionReason);
            $this->addFlash('success', 'dsr.flash.rejected');
        } catch (RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_data_subject_request_show', ['id' => $dsr->getId()]);
    }

    /**
     * Extend deadline to 90 days (Art. 12(3))
     */
    #[Route('/{id}/extend', name: 'extend', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function extend(Request $request, DataSubjectRequest $dsr): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('extend' . $dsr->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_subject_request_show', ['id' => $dsr->getId()]);
        }

        $extensionReason = $request->request->get('extension_reason', '');
        if ($extensionReason === '') {
            $this->addFlash('error', 'dsr.flash.extension_reason_required');
            return $this->redirectToRoute('app_data_subject_request_show', ['id' => $dsr->getId()]);
        }

        try {
            $this->dataSubjectRequestService->extend($dsr, $extensionReason);
            $this->addFlash('success', 'dsr.flash.extended');
        } catch (RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_data_subject_request_show', ['id' => $dsr->getId()]);
    }

    /**
     * Delete a data subject request
     */
    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function delete(Request $request, DataSubjectRequest $dsr): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete' . $dsr->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_subject_request_index');
        }

        $this->dataSubjectRequestService->delete($dsr);

        $this->addFlash('success', 'dsr.flash.deleted');

        return $this->redirectToRoute('app_data_subject_request_index');
    }
}
