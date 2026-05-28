<?php

namespace App\Controller;

use App\Entity\Application;
use App\Entity\Payment;
use App\Form\PaymentType;
use App\Repository\PaymentRepository;
use App\Service\NotificationService;
use App\Service\PaymongoCheckoutHandler;
use App\Service\PaymongoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('payment')]
final class PaymentController extends AbstractController
{
    public function __construct(
        private NotificationService $notificationService,
        private PaymongoCheckoutHandler $paymongoCheckoutHandler,
        private PaymongoService $paymongoService,
    ) {
    }

    /**
     * Step 1: tenant chooses online (GCash/Maya) or card, fills required fields, then continues to Paymongo checkout.
     */
    #[Route('/application/{id}/paymongo', name: 'app_payment_paymongo_setup', methods: ['GET', 'POST'])]
    public function paymongoSetup(Request $request, Application $application): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TENANT');

        $user = $this->getUser();
        if (!$user || $application->getTenant()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You can only pay for your own bookings.');
        }

        if ($application->getStatus() !== 'approved') {
            $this->addFlash('error', 'You can only pay for approved bookings.');
            return $this->redirectToRoute('app_application_index');
        }

        if (!$this->paymongoService->isConfigured()) {
            $this->addFlash('error', 'Online Paymongo payment is not available. Use manual payment instead.');
            return $this->redirectToRoute('app_payment_new', ['id' => $application->getId()]);
        }

        $listing = $application->getListing();
        $defaultAmount = (string) ($listing?->getPrice() ?? '0');

        if ($request->isMethod('POST')) {
            $payload = $request->request->all();
            $payload['amount'] = $payload['amount'] ?? $defaultAmount;
            $payload['paymentChannel'] = $payload['paymentChannel'] ?? '';

            try {
                $result = $this->paymongoCheckoutHandler->startCheckout(
                    $user,
                    $application,
                    $payload,
                    $request->getSchemeAndHttpHost(),
                );
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->render('payment/paymongo_setup.html.twig', [
                    'application' => $application,
                    'default_amount' => $defaultAmount,
                    'options_json' => json_encode($this->paymongoCheckoutHandler->getPaymentOptionsSchema(), JSON_THROW_ON_ERROR),
                    'submitted' => $payload,
                ]);
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->render('payment/paymongo_setup.html.twig', [
                    'application' => $application,
                    'default_amount' => $defaultAmount,
                    'options_json' => json_encode($this->paymongoCheckoutHandler->getPaymentOptionsSchema(), JSON_THROW_ON_ERROR),
                    'submitted' => $payload,
                ]);
            }

            return new RedirectResponse($result['checkoutUrl']);
        }

        return $this->render('payment/paymongo_setup.html.twig', [
            'application' => $application,
            'default_amount' => $defaultAmount,
            'options_json' => json_encode($this->paymongoCheckoutHandler->getPaymentOptionsSchema(), JSON_THROW_ON_ERROR),
            'submitted' => [],
        ]);
    }

    #[Route('/application/{id}/new', name: 'app_payment_new', methods: ['GET', 'POST'])]
    public function new(Request $request, Application $application, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TENANT');

        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        // Only tenant can make payment for their own approved application
        if ($application->getTenant()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You can only make payments for your own applications.');
        }

        if ($application->getStatus() !== 'approved') {
            $this->addFlash('error', 'You can only make payments for approved applications.');
            return $this->redirectToRoute('app_application_index');
        }

        $payment = new Payment();
        $payment->setApplication($application);
        // Default amount is the listing price
        $payment->setAmount((string) $application->getListing()->getPrice());

        $form = $this->createForm(PaymentType::class, $payment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $payment->setStatus('pending');
            $entityManager->persist($payment);
            $entityManager->flush();

            // Notify landlord about payment
            if ($application->getLandlord()) {
                $this->notificationService->notifyUser(
                    $application->getLandlord(),
                    'payment_submitted',
                    sprintf('Tenant %s has submitted a payment of ₱%s for application: %s', 
                        $user->getName(), 
                        number_format((float)$payment->getAmount(), 2),
                        $application->getListing()->getName()
                    ),
                    'Payment',
                    $payment->getId()
                );
            }

            $this->addFlash('success', 'Payment submitted successfully! Waiting for landlord confirmation.');
            return $this->redirectToRoute('app_payment_index', ['applicationId' => $application->getId()]);
        }

        return $this->render('payment/new.html.twig', [
            'payment' => $payment,
            'application' => $application,
            'form' => $form,
        ]);
    }

    #[Route('/application/{applicationId}', name: 'app_payment_index', methods: ['GET'])]
    public function index(int $applicationId, PaymentRepository $paymentRepository, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TENANT');

        $application = $entityManager->getRepository(Application::class)->find($applicationId);
        if (!$application) {
            throw $this->createNotFoundException('Application not found.');
        }

        $user = $this->getUser();
        // Check if user is tenant or landlord of this application
        if ($application->getTenant()->getId() !== $user->getId() && 
            ($application->getLandlord() && $application->getLandlord()->getId() !== $user->getId()) &&
            !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('You can only view payments for your own applications.');
        }

        $payments = $paymentRepository->findByApplication($application);
        $totalPaid = $paymentRepository->getTotalPaidForApplication($application);

        return $this->render('payment/index.html.twig', [
            'payments' => $payments,
            'application' => $application,
            'totalPaid' => $totalPaid,
            'listingPrice' => $application->getListing()->getPrice(),
        ]);
    }

    #[Route('/{id}/approve', name: 'app_payment_approve', methods: ['POST'])]
    public function approve(Payment $payment, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_LANDLORD');

        $user = $this->getUser();
        $application = $payment->getApplication();

        // Only landlord or admin can approve payments
        if (!$this->isGranted('ROLE_ADMIN') && 
            ($application->getLandlord() === null || $application->getLandlord()->getId() !== $user->getId())) {
            throw $this->createAccessDeniedException('You can only approve payments for your own listings.');
        }

        $payment->setStatus('completed');
        $payment->setProcessedBy($user);
        $entityManager->flush();

        $this->addFlash('success', 'Payment approved successfully!');
        return $this->redirectToRoute('app_payment_index', ['applicationId' => $application->getId()]);
    }

    #[Route('/{id}/reject', name: 'app_payment_reject', methods: ['POST'])]
    public function reject(Payment $payment, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_LANDLORD');

        $user = $this->getUser();
        $application = $payment->getApplication();

        if (!$this->isGranted('ROLE_ADMIN') && 
            ($application->getLandlord() === null || $application->getLandlord()->getId() !== $user->getId())) {
            throw $this->createAccessDeniedException('You can only reject payments for your own listings.');
        }

        $payment->setStatus('failed');
        $payment->setProcessedBy($user);
        $entityManager->flush();

        $this->addFlash('warning', 'Payment rejected.');
        return $this->redirectToRoute('app_payment_index', ['applicationId' => $application->getId()]);
    }
}

