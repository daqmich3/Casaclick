<?php

namespace App\Controller;

use App\Entity\Product;
use App\Form\ProductType;
use App\Repository\ProductRepository;
use App\Repository\ApplicationRepository;
use App\Service\NotificationService;
use App\Service\ActivityLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('product')]
final class ProductController extends AbstractController
{
    public function __construct(
        private NotificationService $notificationService,
        private ActivityLogService $activityLogService,
    ) {
    }
    // 🏠 Display all products (limited to avoid memory issues)
    #[Route(name: 'app_product_index', methods: ['GET'])]
    public function index(ProductRepository $productRepository, ApplicationRepository $applicationRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TENANT');

        if ($this->isGranted('ROLE_ADMIN')) {
            // Admin sees approved listings in the public marketplace
            $products = $productRepository->findApprovedWithLandlord();
        } elseif ($this->isGranted('ROLE_TENANT') && !$this->isGranted('ROLE_LANDLORD')) {
            // Tenants can see all listings to browse
            $products = $productRepository->findApprovedWithLandlord();
        } else {
            // Landlords see only their own listings
            $user = $this->getUser();
            $products = $productRepository->findByOwner($user->getId(), 20);
        }

        // Check which listings are occupied and get approved applications
        $occupiedListings = [];
        $approvedApplications = [];
        foreach ($products as $product) {
            if ($applicationRepository->isListingOccupied($product)) {
                $occupiedListings[$product->getId()] = true;
                $approvedApplication = $applicationRepository->findApprovedByListing($product);
                if ($approvedApplication) {
                    $approvedApplications[$product->getId()] = $approvedApplication;
                }
            }
        }

        return $this->render('product/index.html.twig', [
            'products' => $products,
            'occupiedListings' => $occupiedListings,
            'approvedApplications' => $approvedApplications,
        ]);
    }

    // ➕ Create a new product
    #[Route('/new', name: 'app_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TENANT');
        
        // Only landlords and admins can create listings
        if ($this->isGranted('ROLE_TENANT') && !$this->isGranted('ROLE_LANDLORD') && !$this->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Tenants are not allowed to create listings.');
        }

        $product = new Product();
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // 🖼 Handle image upload
            $imageFile = $form->get('image')->getData();

            if ($imageFile) {
                $newFilename = uniqid() . '.' . $imageFile->guessExtension();

                try {
                    $imageFile->move(
                        $this->getParameter('images_directory'),
                        $newFilename
                    );
                } catch (FileException $e) {
                    $this->addFlash('error', '❌ Failed to upload image.');
                }

                $product->setImage($newFilename);
            }

            $product->setCreatedBy($this->getUser());
            // New listings require admin approval
            $product->setStatus('pending');

            $entityManager->persist($product);
            $entityManager->flush();

            // Log create action
            $this->activityLogService->logAction($this->getUser(), 'CREATE', $product);

            // Notify admin if landlord created the listing
            $user = $this->getUser();
            if ($user && in_array('ROLE_LANDLORD', $user->getRoles())) {
                $this->notificationService->notifyAdmin(
                    'listing_created',
                    sprintf('Landlord %s has posted a new listing: %s', $user->getName(), $product->getName()),
                    'Product',
                    $product->getId()
                );
            }

            $this->addFlash('success', '✅ New listing created successfully!');

            return $this->redirectToRoute('app_product_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('product/new.html.twig', [
            'product' => $product,
            'form' => $form,
        ]);
    }

    // 👁 View a single product
    #[Route('/{id}', name: 'app_product_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Product $product, EntityManagerInterface $entityManager, ApplicationRepository $applicationRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TENANT');
        $this->assertCanView($product);

        $user = $this->getUser();
        $hasApplied = false;
        if ($user) {
            $hasApplied = $entityManager->getRepository(\App\Entity\Application::class)
                ->createQueryBuilder('a')
                ->where('a.listing = :listing')
                ->andWhere('a.tenant = :tenant')
                ->setParameter('listing', $product)
                ->setParameter('tenant', $user)
                ->getQuery()
                ->getOneOrNullResult() !== null;
        }

        // Check if listing is occupied
        $isOccupied = $applicationRepository->isListingOccupied($product);
        $approvedApplication = null;
        if ($isOccupied) {
            $approvedApplication = $applicationRepository->findApprovedByListing($product);
        }

        return $this->render('product/show.html.twig', [
            'product' => $product,
            'hasApplied' => $hasApplied,
            'isOccupied' => $isOccupied,
            'approvedApplication' => $approvedApplication,
        ]);
    }

    // ✏️ Edit an existing product
    #[Route('/{id}/edit', name: 'app_product_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Product $product, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TENANT');
        $this->assertCanEdit($product);

        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // 🖼 Handle new image upload if provided
            $imageFile = $form->get('image')->getData();

            if ($imageFile) {
                $newFilename = uniqid() . '.' . $imageFile->guessExtension();

                try {
                    $imageFile->move(
                        $this->getParameter('images_directory'),
                        $newFilename
                    );
                } catch (FileException $e) {
                    $this->addFlash('error', '❌ Failed to upload new image.');
                }

                $product->setImage($newFilename);
            }

            $product->setUpdatedBy($this->getUser());

            $entityManager->flush();

            // Log update action
            $this->activityLogService->logAction($this->getUser(), 'UPDATE', $product);

            // Notify admin if landlord edited the listing
            $user = $this->getUser();
            if ($user && in_array('ROLE_LANDLORD', $user->getRoles())) {
                $this->notificationService->notifyAdmin(
                    'listing_updated',
                    sprintf('Landlord %s has updated a listing: %s', $user->getName(), $product->getName()),
                    'Product',
                    $product->getId()
                );
            }

            $this->addFlash('success', '✅ Listing updated successfully!');

            return $this->redirectToRoute('app_product_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('product/edit.html.twig', [
            'product' => $product,
            'form' => $form,
        ]);
    }

    // 🗑 Delete a product
    #[Route('/{id}', name: 'app_product_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Product $product, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TENANT');
        $this->assertCanEdit($product);

        if ($this->isCsrfTokenValid('delete' . $product->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($product);
            $entityManager->flush();
                // Log delete action
                $this->activityLogService->logAction($this->getUser(), 'DELETE', $product);
            $this->addFlash('success', '🗑 Listing deleted successfully.');
        }

        return $this->redirectToRoute('app_product_index', [], Response::HTTP_SEE_OTHER);
    }

    // 👀 Admin: view pending listings waiting for approval
    #[Route('/pending', name: 'app_product_pending', methods: ['GET'])]
    public function pending(ProductRepository $productRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $products = $productRepository->findPendingWithLandlord();

        return $this->render('product/pending.html.twig', [
            'products' => $products,
        ]);
    }

    // ✅ Admin: approve a pending listing
    #[Route('/{id}/approve', name: 'app_product_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(Product $product, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('approve' . $product->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('app_product_pending');
        }

        $product->setStatus('approved');
        $entityManager->flush();

        // Log action
        $this->activityLogService->logAction($this->getUser(), 'APPROVE_LISTING', $product);

        $this->addFlash('success', 'Listing approved and is now visible in Active Listings.');

        return $this->redirectToRoute('app_product_pending');
    }

    // ❌ Admin: reject a pending listing
    #[Route('/{id}/reject', name: 'app_product_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(Product $product, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('reject' . $product->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('app_product_pending');
        }

        $product->setStatus('rejected');
        $entityManager->flush();

        // Log action
        $this->activityLogService->logAction($this->getUser(), 'REJECT_LISTING', $product);

        $this->addFlash('success', 'Listing has been rejected.');

        return $this->redirectToRoute('app_product_pending');
    }

    // 🔓 Unoccupy a listing (make it available again)
    #[Route('/{id}/unoccupy', name: 'app_product_unoccupy', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unoccupy(Request $request, Product $product, EntityManagerInterface $entityManager, ApplicationRepository $applicationRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_TENANT');
        $this->assertCanEdit($product);

        // Verify CSRF token
        if (!$this->isCsrfTokenValid('unoccupy' . $product->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('app_product_index', [], Response::HTTP_SEE_OTHER);
        }

        // Check if listing is actually occupied
        if (!$applicationRepository->isListingOccupied($product)) {
            $this->addFlash('error', 'This listing is not currently occupied.');
            return $this->redirectToRoute('app_product_index', [], Response::HTTP_SEE_OTHER);
        }

        // Find and update the approved application
        $approvedApplication = $applicationRepository->findApprovedByListing($product);
        if ($approvedApplication) {
            // Change status to 'completed' to indicate the tenancy has ended
            $approvedApplication->setStatus('completed');
            $entityManager->flush();

            // Log the action
            $this->activityLogService->logAction($this->getUser(), 'UNOCCUPY', $product);

            // Notify the tenant if they exist
            if ($approvedApplication->getTenant()) {
                $this->notificationService->notifyUser(
                    $approvedApplication->getTenant(),
                    'listing_unoccupied',
                    sprintf('The listing "%s" has been made available again by the landlord.', $product->getName()),
                    'Product',
                    $product->getId()
                );
            }

            $this->addFlash('success', '✅ Listing has been unoccupied and is now available in the market.');
        } else {
            $this->addFlash('error', 'Could not find the approved application for this listing.');
        }

        return $this->redirectToRoute('app_product_index', [], Response::HTTP_SEE_OTHER);
    }

    private function assertCanView(Product $product): void
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return;
        }

        // Tenants (without landlord role) can view all listings
        if ($this->isGranted('ROLE_TENANT') && !$this->isGranted('ROLE_LANDLORD')) {
            return;
        }

        // Landlords can only view their own listings
        $user = $this->getUser();
        if ($product->getCreatedBy() && $product->getCreatedBy()->getId() === $user->getId()) {
            return;
        }

        throw new AccessDeniedException();
    }

    private function assertCanEdit(Product $product): void
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return;
        }

        // Tenants (without landlord role) cannot edit listings
        if ($this->isGranted('ROLE_TENANT') && !$this->isGranted('ROLE_LANDLORD')) {
            throw new AccessDeniedException('Tenants are not allowed to edit listings.');
        }

        // Only landlords can edit their own listings
        $user = $this->getUser();
        if ($product->getCreatedBy() && $product->getCreatedBy()->getId() === $user->getId()) {
            return;
        }

        throw new AccessDeniedException();
    }
}
