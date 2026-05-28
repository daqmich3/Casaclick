<?php

namespace App\Controller;

use App\Entity\Application;
use App\Entity\Product;
use App\Repository\ApplicationRepository;
use App\Repository\ProductRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('application')]
final class ApplicationController extends AbstractController
{
    public function __construct(
        private NotificationService $notificationService
    ) {
    }

    #[Route('/listing/{id}/apply', name: 'app_application_new', methods: ['POST'])]
    public function apply(Request $request, Product $listing, EntityManagerInterface $entityManager, ApplicationRepository $applicationRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TENANT');

        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        // Check if listing is already occupied
        if ($applicationRepository->isListingOccupied($listing)) {
            $this->addFlash('error', 'This listing is already occupied. You cannot apply for it.');
            return $this->redirectToRoute('app_product_show', ['id' => $listing->getId()]);
        }

        // Prevent landlords from applying to their own listings
        if ($listing->getCreatedBy() && $listing->getCreatedBy()->getId() === $user->getId()) {
            $this->addFlash('error', 'You cannot apply to your own listing.');
            return $this->redirectToRoute('app_product_show', ['id' => $listing->getId()]);
        }

        // Check if tenant already applied
        $existingApplication = $entityManager->getRepository(Application::class)
            ->createQueryBuilder('a')
            ->where('a.listing = :listing')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('listing', $listing)
            ->setParameter('tenant', $user)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existingApplication) {
            $this->addFlash('error', 'You have already applied for this listing.');
            return $this->redirectToRoute('app_product_show', ['id' => $listing->getId()]);
        }

        $application = new Application();
        $application->setListing($listing);
        $application->setTenant($user);
        $application->setLandlord($listing->getCreatedBy());
        $application->setStatus('pending');
        $application->setMessage($request->request->get('message', ''));

        $entityManager->persist($application);
        $entityManager->flush();

        // Notify landlord
        if ($listing->getCreatedBy()) {
            $this->notificationService->notifyUser(
                $listing->getCreatedBy(),
                'application_submitted',
                sprintf('Tenant %s has applied for your listing: %s', $user->getName(), $listing->getName()),
                'Application',
                $application->getId()
            );
        }

        $this->addFlash('success', 'Application submitted successfully!');
        return $this->redirectToRoute('app_product_show', ['id' => $listing->getId()]);
    }

    #[Route('/{id}/approve', name: 'app_application_approve', methods: ['POST'])]
    public function approve(Application $application, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_LANDLORD');

        $user = $this->getUser();
        if (!$user || $application->getLandlord()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You can only approve applications for your own listings.');
        }

        $application->setStatus('approved');
        $entityManager->flush();

        $this->addFlash('success', 'Application approved successfully!');
        return $this->redirectToRoute('app_application_index');
    }

    #[Route('/{id}/reject', name: 'app_application_reject', methods: ['POST'])]
    public function reject(Application $application, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_LANDLORD');

        $user = $this->getUser();
        if (!$user || $application->getLandlord()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('You can only reject applications for your own listings.');
        }

        $application->setStatus('rejected');
        $entityManager->flush();

        $this->addFlash('success', 'Application rejected.');
        return $this->redirectToRoute('app_application_index');
    }

    #[Route(name: 'app_application_index', methods: ['GET'])]
    public function index(ApplicationRepository $applicationRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TENANT');

        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        if ($this->isGranted('ROLE_LANDLORD')) {
            // Landlord sees applications for their listings
            $applications = $applicationRepository->findByLandlord($user);
        } else {
            // Tenant sees their own applications
            $applications = $applicationRepository->findByTenant($user);
        }

        return $this->render('application/index.html.twig', [
            'applications' => $applications,
        ]);
    }
}

