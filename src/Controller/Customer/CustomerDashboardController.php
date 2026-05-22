<?php

namespace App\Controller\Customer;

use App\Entity\User;
use App\Repository\ApplicationRepository;
use App\Repository\NotificationRepository;
use App\Repository\PaymentRepository;
use App\Repository\ProductRepository;
use App\Service\LiveSyncRevisionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Customer (tenant) dashboard — no admin tools, user management, or activity logs.
 */
#[Route('/customer')]
final class CustomerDashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_customer_dashboard', methods: ['GET'])]
    public function dashboard(
        ApplicationRepository $applicationRepository,
        PaymentRepository $paymentRepository,
        NotificationRepository $notificationRepository,
        ProductRepository $productRepository,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if ($user->getPrimaryRole() !== 'ROLE_TENANT') {
            throw $this->createAccessDeniedException('This dashboard is for customers (tenants) only.');
        }

        $applications = $applicationRepository->findByTenant($user);
        $payments = $paymentRepository->findByUser($user);
        $listingCount = count($productRepository->findApprovedWithLandlord());

        return $this->render('customer/dashboard.html.twig', [
            'user' => $user,
            'listingCount' => $listingCount,
            'applicationCount' => count($applications),
            'paymentCount' => count($payments),
            'unreadNotifications' => $notificationRepository->countUnreadByUser($user),
            'recentApplications' => array_slice($applications, 0, 5),
            'recentPayments' => array_slice($payments, 0, 5),
        ]);
    }

    /** Live feed for customer web dashboard (poll ~8s — matches mobile USB sync). */
    #[Route('/dashboard/feed', name: 'app_customer_dashboard_feed', methods: ['GET'])]
    public function dashboardFeed(LiveSyncRevisionService $liveSyncRevision): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['success' => false], Response::HTTP_UNAUTHORIZED);
        }
        if ($user->getPrimaryRole() !== 'ROLE_TENANT') {
            return new JsonResponse(['success' => false], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(array_merge(
            ['success' => true],
            $liveSyncRevision->buildForUser($user),
        ));
    }
}
