<?php

namespace App\Controller\Api;

use App\Entity\Application;
use App\Entity\Payment;
use App\Entity\Product;
use App\Service\MobileUserProvisioningService;
use App\Entity\User;
use App\Repository\ApplicationRepository;
use App\Repository\NotificationRepository;
use App\Repository\PaymentRepository;
use App\Repository\ProductRepository;
use App\Repository\CategoryRepository;
use App\Repository\UserRepository;
use App\Service\ActivityLogService;
use App\Service\LiveSyncRevisionService;
use App\Service\EmailVerificationService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/mobile', name: 'api_mobile_')]
class MobileApiController extends AbstractController
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly ActivityLogService $activityLogService,
        private readonly MobileUserProvisioningService $mobileUserProvisioning,
    ) {
    }

    /** Connection probe for React Native (same pattern as khrings/Appdev /api/health) */
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json([
            'success' => true,
            'message' => 'CasaClick mobile API is running',
            'service' => 'casaclick',
            'time' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /** Landing page content (mirrors /home) */
    #[Route('/home', name: 'home', methods: ['GET'])]
    public function home(ProductRepository $productRepository): JsonResponse
    {
        $listingCount = count($productRepository->findApprovedWithLandlord());

        return $this->json([
            'success' => true,
            'data' => [
                'title' => 'Find Your Home in Just a Click',
                'tagline' => 'Discover verified apartments across multiple cities. Trusted listings, easy booking.',
                'features' => [
                    ['title' => 'Wide Selection', 'description' => 'Discover apartments that match your needs across multiple cities and neighborhoods.'],
                    ['title' => 'Trusted Listings', 'description' => 'Every listing is verified for your peace of mind. No scams, no surprises.'],
                    ['title' => 'Easy Booking', 'description' => 'Schedule visits and reserve apartments with just a few clicks.'],
                ],
                'steps' => [
                    ['step' => 1, 'title' => 'Create Account', 'description' => 'Sign up as a tenant or landlord in seconds.'],
                    ['step' => 2, 'title' => 'Browse & Apply', 'description' => 'Search listings and submit applications online.'],
                    ['step' => 3, 'title' => 'Move In', 'description' => 'Get approved and move into your new home.'],
                ],
                'stats' => [
                    ['label' => 'Active Listings', 'value' => (string) $listingCount],
                    ['label' => 'Happy Tenants', 'value' => '1200+'],
                    ['label' => 'Cities', 'value' => '50+'],
                    ['label' => 'Satisfaction Rate', 'value' => '98%'],
                ],
            ],
        ]);
    }

    #[Route('/about', name: 'about', methods: ['GET'])]
    public function about(): JsonResponse
    {
        return $this->json([
            'success' => true,
            'data' => [
                'title' => 'About CasaClick',
                'description' => 'CasaClick connects tenants and landlords with verified rental listings and a simple application process.',
                'team' => [
                    ['name' => 'Maria Santos', 'position' => 'CEO & Co-Founder', 'bio' => '15+ years in real estate. Passionate about making housing accessible.'],
                    ['name' => 'John Davis', 'position' => 'CTO', 'bio' => 'Tech innovator building the future of property management.'],
                    ['name' => 'Sarah Lee', 'position' => 'Head of Operations', 'bio' => 'Ensures every listing meets our quality standards.'],
                    ['name' => 'Michael Chen', 'position' => 'Head of Customer Success', 'bio' => 'Dedicated to helping tenants and landlords succeed.'],
                ],
            ],
        ]);
    }

    #[Route('/contact', name: 'contact', methods: ['GET'])]
    public function contact(): JsonResponse
    {
        return $this->json([
            'success' => true,
            'data' => [
                'title' => 'Contact Us',
                'description' => 'Have questions? We would love to hear from you.',
                'email' => 'support@casaclick.com',
                'note' => 'Use the contact form on the website or email us directly.',
            ],
        ]);
    }

    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function dashboard(
        ApplicationRepository $applicationRepository,
        PaymentRepository $paymentRepository,
        NotificationRepository $notificationRepository,
        ProductRepository $productRepository,
        UserRepository $userRepository,
    ): JsonResponse {
        $user = $this->requireUser();
        $primaryRole = $user->getPrimaryRole();
        $unread = $notificationRepository->countUnreadByUser($user);
        $notifications = array_map(
            static fn ($n) => [
                'id' => $n->getId(),
                'type' => $n->getType(),
                'message' => $n->getMessage(),
                'isRead' => $n->isRead(),
                'createdAt' => $n->getCreatedAt()->format('c'),
            ],
            $notificationRepository->findByUser($user, 5),
        );

        $data = match ($primaryRole) {
            'ROLE_ADMIN', 'ROLE_STAFF' => $this->buildStaffDashboardData(
                $user,
                $primaryRole,
                $productRepository,
                $userRepository,
                $unread,
                $notifications,
            ),
            'ROLE_LANDLORD' => $this->buildLandlordDashboardData(
                $user,
                $applicationRepository,
                $paymentRepository,
                $productRepository,
                $unread,
                $notifications,
            ),
            default => $this->buildTenantDashboardData(
                $user,
                $applicationRepository,
                $paymentRepository,
                $productRepository,
                $unread,
                $notifications,
            ),
        };

        return $this->json(['success' => true, 'data' => $data]);
    }

    #[Route('/my-listings/revision', name: 'my_listings_revision', methods: ['GET'])]
    public function myListingsRevision(ProductRepository $productRepository): JsonResponse
    {
        $user = $this->requireUser();
        if (!$this->isGranted('ROLE_LANDLORD')) {
            return $this->json(['success' => false, 'error' => 'Landlord access only'], Response::HTTP_FORBIDDEN);
        }

        $meta = $productRepository->getOwnerListingsSyncMeta((int) $user->getId());
        $revision = ProductRepository::buildSyncRevision($meta['count'], $meta['latestUpdatedAt']);

        return $this->json([
            'success' => true,
            'revision' => $revision,
            'count' => $meta['count'],
            'latestUpdatedAt' => $meta['latestUpdatedAt'],
            'serverTime' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /** Landlord's own property listings (mirrors web Active Listings for owner). */
    #[Route('/my-listings', name: 'my_listings', methods: ['GET'])]
    public function myListings(ProductRepository $productRepository): JsonResponse
    {
        $user = $this->requireUser();
        if (!$this->isGranted('ROLE_LANDLORD')) {
            return $this->json(['success' => false, 'error' => 'Landlord access only'], Response::HTTP_FORBIDDEN);
        }

        $userId = (int) $user->getId();
        $products = $productRepository->findByOwner($userId, 50);
        $meta = $productRepository->getOwnerListingsSyncMeta($userId);
        $data = array_map(fn (Product $p) => $this->serializeListingTile($p), $products);

        return $this->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'revision' => ProductRepository::buildSyncRevision($meta['count'], $meta['latestUpdatedAt']),
                'latestUpdatedAt' => $meta['latestUpdatedAt'],
            ],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $notifications
     *
     * @return array<string, mixed>
     */
    private function buildTenantDashboardData(
        User $user,
        ApplicationRepository $applicationRepository,
        PaymentRepository $paymentRepository,
        ProductRepository $productRepository,
        int $unread,
        array $notifications,
    ): array {
        $applications = $applicationRepository->findByTenant($user);
        $payments = $paymentRepository->findByUser($user);
        $listingCount = count($productRepository->findApprovedWithLandlord());

        return [
            'role' => 'ROLE_TENANT',
            'roleLabel' => 'Customer',
            'eyebrow' => 'Customer dashboard',
            'title' => 'Dashboard',
            'subtitle' => sprintf(
                'Welcome back%s. Browse listings, bookings, and Paymongo payments — synced with the website.',
                $user->getName() ? ', ' . $user->getName() : '',
            ),
            'listingCount' => $listingCount,
            'applicationCount' => count($applications),
            'paymentCount' => count($payments),
            'unreadNotifications' => $unread,
            'stats' => [
                ['key' => 'listings', 'title' => 'Available listings', 'value' => (string) $listingCount, 'hint' => 'Approved on marketplace', 'icon' => 'home'],
                ['key' => 'applications', 'title' => 'My applications', 'value' => (string) count($applications), 'hint' => 'Submitted & approved', 'icon' => 'file'],
                ['key' => 'payments', 'title' => 'Payments', 'value' => (string) count($payments), 'hint' => 'Rent payment history', 'icon' => 'money'],
                ['key' => 'notifications', 'title' => 'Notifications', 'value' => (string) $unread, 'hint' => 'Unread alerts', 'icon' => 'bell'],
            ],
            'quickLinks' => [
                ['id' => 'listings', 'label' => 'Browse listings', 'subtitle' => 'Active listings on web', 'icon' => 'list'],
                ['id' => 'applications', 'label' => 'My applications', 'subtitle' => 'Application status', 'icon' => 'file'],
                ['id' => 'payments', 'label' => 'Payments', 'subtitle' => 'Pay rent & history', 'icon' => 'money'],
                ['id' => 'notifications', 'label' => 'Notifications', 'subtitle' => 'Alerts from landlords', 'icon' => 'bell', 'badge' => $unread],
                ['id' => 'profile', 'label' => 'My profile', 'subtitle' => 'Account on website', 'icon' => 'user'],
            ],
            'recentListings' => array_map(
                fn (Product $p) => $this->serializeListingTile($p),
                array_slice($productRepository->findApprovedWithLandlord(), 0, 4),
            ),
            'recentApplications' => array_map(
                fn (Application $a) => $this->serializeApplicationSummary($a),
                array_slice($applications, 0, 4),
            ),
            'notifications' => $notifications,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $notifications
     *
     * @return array<string, mixed>
     */
    private function buildLandlordDashboardData(
        User $user,
        ApplicationRepository $applicationRepository,
        PaymentRepository $paymentRepository,
        ProductRepository $productRepository,
        int $unread,
        array $notifications,
    ): array {
        $myListings = $productRepository->findByOwner((int) $user->getId(), 50);
        $applications = $applicationRepository->findByLandlord($user);
        $pending = $applicationRepository->findPendingByLandlord($user);
        $payments = $paymentRepository->findByUser($user);

        return [
            'role' => 'ROLE_LANDLORD',
            'roleLabel' => 'Landlord',
            'eyebrow' => 'Landlord overview',
            'title' => 'Dashboard',
            'subtitle' => sprintf(
                'Welcome back%s. Manage your listings and review tenant applications.',
                $user->getName() ? ', ' . $user->getName() : '',
            ),
            'listingCount' => count($myListings),
            'applicationCount' => count($applications),
            'paymentCount' => count($payments),
            'unreadNotifications' => $unread,
            'pendingApplicationCount' => count($pending),
            'stats' => [
                ['key' => 'my_listings', 'title' => 'My listings', 'value' => (string) count($myListings), 'hint' => 'Properties you posted', 'icon' => 'home'],
                ['key' => 'pending_apps', 'title' => 'Pending applications', 'value' => (string) count($pending), 'hint' => 'Awaiting your review', 'icon' => 'clock'],
                ['key' => 'applications', 'title' => 'All applications', 'value' => (string) count($applications), 'hint' => 'Tenant requests', 'icon' => 'file'],
                ['key' => 'notifications', 'title' => 'Notifications', 'value' => (string) $unread, 'hint' => 'Unread alerts', 'icon' => 'bell'],
            ],
            'quickLinks' => [
                ['id' => 'my_listings', 'label' => 'My listings', 'subtitle' => 'Active listings you own', 'icon' => 'home'],
                ['id' => 'applications', 'label' => 'Applications', 'subtitle' => 'Review tenant requests', 'icon' => 'file', 'badge' => count($pending)],
                ['id' => 'listings', 'label' => 'Marketplace', 'subtitle' => 'All approved listings', 'icon' => 'list'],
                ['id' => 'payments', 'label' => 'Payments', 'subtitle' => 'Tenant payment records', 'icon' => 'money'],
                ['id' => 'notifications', 'label' => 'Notifications', 'subtitle' => 'Listing & application alerts', 'icon' => 'bell', 'badge' => $unread],
                ['id' => 'profile', 'label' => 'My profile', 'subtitle' => 'Account settings', 'icon' => 'user'],
            ],
            'recentListings' => array_map(
                fn (Product $p) => $this->serializeListingTile($p),
                array_slice($myListings, 0, 4),
            ),
            'recentApplications' => array_map(
                fn (Application $a) => $this->serializeApplicationSummary($a),
                array_slice($applications, 0, 4),
            ),
            'notifications' => $notifications,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $notifications
     *
     * @return array<string, mixed>
     */
    private function buildStaffDashboardData(
        User $user,
        string $primaryRole,
        ProductRepository $productRepository,
        UserRepository $userRepository,
        int $unread,
        array $notifications,
    ): array {
        $isAdmin = $primaryRole === 'ROLE_ADMIN';
        $products = $productRepository->findRecent(6);
        $pendingListings = $productRepository->findPendingWithLandlord();
        $totalRevenue = $productRepository->getTotalPriceSum();

        $quickLinks = [
            ['id' => 'listings', 'label' => 'Active listings', 'subtitle' => 'Marketplace listings', 'icon' => 'list'],
            ['id' => 'applications', 'label' => 'Applications', 'subtitle' => 'Tenant requests', 'icon' => 'file'],
            ['id' => 'notifications', 'label' => 'Notifications', 'subtitle' => 'System alerts', 'icon' => 'bell', 'badge' => $unread],
            ['id' => 'profile', 'label' => 'My profile', 'subtitle' => 'Account settings', 'icon' => 'user'],
        ];

        if ($isAdmin) {
            array_unshift($quickLinks, [
                'id' => 'admin_area',
                'label' => 'Admin area',
                'subtitle' => 'Users & activity logs on web',
                'icon' => 'cog',
            ]);
        }

        return [
            'role' => $primaryRole,
            'roleLabel' => $isAdmin ? 'Admin' : 'Staff',
            'eyebrow' => $isAdmin ? 'Admin overview' : 'Staff overview',
            'title' => 'Dashboard',
            'subtitle' => sprintf(
                'Welcome back%s. Snapshot of listings, users, and notifications — matches the website staff dashboard.',
                $user->getName() ? ', ' . $user->getName() : '',
            ),
            'listingCount' => $productRepository->getTotalCount(),
            'applicationCount' => 0,
            'paymentCount' => 0,
            'unreadNotifications' => $unread,
            'pendingListingCount' => count($pendingListings),
            'stats' => [
                ['key' => 'properties', 'title' => 'Total properties', 'value' => (string) $productRepository->getTotalCount(), 'hint' => 'Across marketplace', 'icon' => 'home'],
                ['key' => 'revenue', 'title' => 'Combined listing value', 'value' => '₱' . number_format($totalRevenue, 0, '.', ','), 'hint' => 'Sum of listed prices', 'icon' => 'money'],
                ['key' => 'users', 'title' => 'Registered users', 'value' => (string) $userRepository->countAll(), 'hint' => 'All roles', 'icon' => 'users'],
                ['key' => 'landlords', 'title' => 'Landlords', 'value' => (string) $userRepository->countByRole('ROLE_LANDLORD'), 'hint' => 'Landlord accounts', 'icon' => 'user'],
            ],
            'quickLinks' => $quickLinks,
            'recentListings' => array_map(
                fn (Product $p) => $this->serializeListingTile($p),
                array_slice($products, 0, 4),
            ),
            'recentApplications' => [],
            'notifications' => $notifications,
            'adminNote' => $isAdmin
                ? 'User management and activity logs are available on the CasaClick website admin area.'
                : 'Contact an administrator for user management on the website.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMarketplaceListing(Product $p, ApplicationRepository $applicationRepository): array
    {
        return [
            'id' => $p->getId(),
            'name' => $p->getName(),
            'price' => $p->getPrice(),
            'description' => $p->getDescription(),
            'image' => $p->getImage() ? '/uploads/images/' . $p->getImage() : null,
            'category' => $p->getCategory()?->getName(),
            'landlord' => $p->getCreatedBy() ? ['name' => $p->getCreatedBy()->getName()] : null,
            'occupied' => $applicationRepository->isListingOccupied($p),
            'status' => $p->getStatus(),
            'createdAt' => $p->getCreatedAt()?->format('c'),
            'updatedAt' => $p->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeListingTile(Product $p): array
    {
        return [
            'id' => $p->getId(),
            'name' => $p->getName(),
            'price' => $p->getPrice(),
            'image' => $p->getImage() ? '/uploads/images/' . $p->getImage() : null,
            'category' => $p->getCategory()?->getName(),
            'status' => $p->getStatus(),
            'updatedAt' => $p->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeApplicationSummary(Application $a): array
    {
        $listing = $a->getListing();

        return [
            'id' => $a->getId(),
            'status' => $a->getStatus(),
            'listingName' => $listing?->getName(),
            'tenantName' => $a->getTenant()?->getName(),
            'createdAt' => $a->getCreatedAt()?->format('c'),
        ];
    }

    #[Route('/me', name: 'me', methods: ['GET', 'PATCH'])]
    public function me(Request $request, EntityManagerInterface $em): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json([
                'success' => false,
                'error' => 'Unauthorized',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ($request->isMethod('PATCH')) {
            $payload = json_decode($request->getContent(), true) ?? [];
            $errors = [];

            if (array_key_exists('name', $payload)) {
                $name = trim((string) $payload['name']);
                if (strlen($name) < 2) {
                    $errors[] = 'Name must be at least 2 characters';
                } else {
                    $user->setName($name);
                }
            }

            if (array_key_exists('phone', $payload)) {
                $user->setPhone(trim((string) $payload['phone']) ?: null);
            }

            if ($errors !== []) {
                return $this->json(['success' => false, 'errors' => $errors], Response::HTTP_BAD_REQUEST);
            }

            $em->flush();

            return $this->json([
                'success' => true,
                'data' => $this->serializeMobileUser($user),
            ]);
        }

        return $this->json([
            'success' => true,
            'data' => $this->serializeMobileUser($user),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMobileUser(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'phone' => $user->getPhone(),
            'roles' => $user->getRoles(),
            'roleLabel' => $user->getPrimaryRole() === 'ROLE_TENANT' ? 'Renter' : 'Landlord',
            'emailVerified' => $user->isEmailVerified(),
        ];
    }

    /**
     * Combined fingerprint for mobile dashboard (bookings + payments + listings).
     * Poll every ~8s while USB-connected — same MySQL as the website.
     */
    #[Route('/sync/revision', name: 'sync_revision', methods: ['GET'])]
    public function syncRevision(LiveSyncRevisionService $liveSyncRevision): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(array_merge(
            ['success' => true],
            $liveSyncRevision->buildForUser($user),
        ));
    }

    #[Route('/applications/revision', name: 'applications_revision', methods: ['GET'])]
    public function applicationsRevision(
        ApplicationRepository $applicationRepository,
    ): JsonResponse {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $meta = $this->isGranted('ROLE_LANDLORD')
            ? $applicationRepository->getSyncMetaForLandlord($user)
            : $applicationRepository->getSyncMetaForTenant($user);
        $revision = ProductRepository::buildSyncRevision($meta['count'], $meta['latestUpdatedAt']);

        return $this->json([
            'success' => true,
            'revision' => $revision,
            'count' => $meta['count'],
            'serverTime' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /** Lightweight check for mobile live sync (poll every few seconds). */
    #[Route('/listings/revision', name: 'listings_revision', methods: ['GET'])]
    public function listingsRevision(ProductRepository $productRepository): JsonResponse
    {
        $meta = $productRepository->getApprovedMarketplaceSyncMeta();
        $revision = ProductRepository::buildSyncRevision($meta['count'], $meta['latestUpdatedAt']);

        return $this->json([
            'success' => true,
            'revision' => $revision,
            'count' => $meta['count'],
            'latestUpdatedAt' => $meta['latestUpdatedAt'],
            'serverTime' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/listings', name: 'listings', methods: ['GET'])]
    public function listings(
        ProductRepository $productRepository,
        ApplicationRepository $applicationRepository,
    ): JsonResponse {
        $products = $productRepository->findApprovedWithLandlord();
        $meta = $productRepository->getApprovedMarketplaceSyncMeta();

        $data = array_map(
            fn (Product $p) => $this->serializeMarketplaceListing($p, $applicationRepository),
            $products,
        );

        return $this->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'revision' => ProductRepository::buildSyncRevision($meta['count'], $meta['latestUpdatedAt']),
                'latestUpdatedAt' => $meta['latestUpdatedAt'],
            ],
        ], Response::HTTP_OK, [], ['json_encode_options' => JSON_UNESCAPED_SLASHES]);
    }

    #[Route('/listings/{id}', name: 'listing_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function listingShow(
        int $id,
        ProductRepository $productRepository,
        ApplicationRepository $applicationRepository,
    ): JsonResponse {
        $product = $productRepository->findApprovedById($id);

        if (!$product) {
            return $this->json(['success' => false, 'error' => 'Listing not found'], Response::HTTP_NOT_FOUND);
        }

        $data = $this->serializeMarketplaceListing($product, $applicationRepository);

        return $this->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'revision' => ProductRepository::buildSyncRevision(
                    1,
                    $product->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
                ),
            ],
        ]);
    }

    #[Route('/categories', name: 'categories', methods: ['GET'])]
    public function categories(CategoryRepository $categoryRepository): JsonResponse
    {
        $categories = $categoryRepository->findBy([], ['name' => 'ASC']);

        $data = array_map(fn ($c) => [
            'id' => $c->getId(),
            'name' => $c->getName(),
        ], $categories);

        return $this->json([
            'success' => true,
            'data' => $data,
            'meta' => ['count' => count($data)],
        ]);
    }

    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        EmailVerificationService $verificationService,
        JWTTokenManagerInterface $jwtManager,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true) ?? [];

        $name = trim((string) ($payload['name'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $plainPassword = (string) ($payload['password'] ?? '');
        $confirmPassword = (string) ($payload['confirmPassword'] ?? $payload['confirm_password'] ?? '');
        $role = in_array($payload['role'] ?? '', ['ROLE_LANDLORD', 'ROLE_TENANT'], true)
            ? $payload['role']
            : 'ROLE_TENANT';
        $platform = trim((string) ($payload['platform'] ?? 'mobile'));

        $errors = [];
        if (strlen($name) < 2) {
            $errors[] = 'Full name must be at least 2 characters';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address';
        }
        if (strlen($plainPassword) < 8) {
            $errors[] = 'Password must be at least 8 characters';
        }
        if ($confirmPassword !== '' && $plainPassword !== $confirmPassword) {
            $errors[] = 'Passwords do not match';
        }
        if ($role === 'ROLE_TENANT' && strlen(preg_replace('/\D/', '', $phone) ?? '') < 10) {
            $errors[] = 'Valid phone number is required for renters';
        }

        if (!empty($errors)) {
            return $this->json([
                'success' => false,
                'errors' => $errors,
            ], Response::HTTP_BAD_REQUEST);
        }

        $existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing) {
            return $this->json([
                'success' => false,
                'error' => 'Email already registered',
            ], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setName($name);
        $user->setEmail($email);
        $user->setPhone($phone !== '' ? $phone : null);
        $user->setRoles([$role]);
        $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
        $user->setEmailVerified(true);
        $em->persist($user);

        $this->mobileUserProvisioning->ensureTenantProfile($user);

        $em->flush();

        try {
            $verificationService->sendVerificationEmail($user);
            $em->flush();
        } catch (\Throwable $e) {
            // Optional welcome email
        }

        $this->activityLogService->logEvent(
            $user,
            'MOBILE_REGISTER',
            sprintf('Renter account created (%s)', $email),
            null,
            'App\\Entity\\User',
            (string) $user->getId(),
            $request->getClientIp(),
            $platform,
        );

        $jwt = $jwtManager->create($user);

        return $this->json([
            'success' => true,
            'token' => $jwt,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'phone' => $user->getPhone(),
                'roles' => $user->getRoles(),
                'roleLabel' => $role === 'ROLE_TENANT' ? 'Renter' : 'Landlord',
                'emailVerified' => $user->isEmailVerified(),
            ],
            'message' => 'Registration successful. Welcome to CasaClick.',
        ], Response::HTTP_CREATED);
    }

    #[Route('/verify-email/{token}', name: 'verify_email', methods: ['GET'])]
    public function verifyEmail(
        string $token,
        UserRepository $userRepository,
        EntityManagerInterface $em,
    ): JsonResponse {
        $user = $userRepository->findOneBy(['verificationToken' => $token]);

        if (!$user) {
            return $this->json([
                'success' => false,
                'error' => 'Invalid or expired verification link',
            ], Response::HTTP_BAD_REQUEST);
        }

        $user->setEmailVerified(true);
        $user->setVerificationToken(null);
        $em->flush();

        return $this->json([
            'success' => true,
            'data' => [
                'email' => $user->getEmail(),
                'emailVerified' => true,
                'message' => 'Email verified successfully.',
            ],
        ]);
    }

    #[Route('/applications', name: 'applications', methods: ['GET'])]
    public function applications(ApplicationRepository $applicationRepository): JsonResponse
    {
        $user = $this->requireUser();
        $applications = $this->isGranted('ROLE_LANDLORD')
            ? $applicationRepository->findByLandlord($user)
            : $applicationRepository->findByTenant($user);

        $data = array_map(fn (Application $a) => $this->serializeApplication($a), $applications);

        return $this->json(['success' => true, 'data' => $data, 'meta' => ['count' => count($data)]]);
    }

    #[Route('/applications/{id}', name: 'application_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function applicationShow(int $id, ApplicationRepository $applicationRepository, PaymentRepository $paymentRepository): JsonResponse
    {
        $user = $this->requireUser();
        $application = $applicationRepository->find($id);
        if (!$application || !$this->canAccessApplication($user, $application)) {
            return $this->json(['success' => false, 'error' => 'Application not found'], Response::HTTP_NOT_FOUND);
        }

        $data = $this->serializeApplication($application, true);
        $data['payments'] = array_map(
            fn (Payment $p) => $this->serializePayment($p),
            $paymentRepository->findByApplication($application)
        );

        return $this->json(['success' => true, 'data' => $data]);
    }

    #[Route('/listings/{id}/apply', name: 'listing_apply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function applyToListing(
        int $id,
        Request $request,
        ProductRepository $productRepository,
        ApplicationRepository $applicationRepository,
        EntityManagerInterface $em,
    ): JsonResponse {
        $user = $this->requireUser();
        if (!$this->isGranted('ROLE_TENANT')) {
            return $this->json(['success' => false, 'error' => 'Only tenants can apply for listings'], Response::HTTP_FORBIDDEN);
        }

        $listing = $productRepository->findApprovedById($id);
        if (!$listing) {
            return $this->json(['success' => false, 'error' => 'Listing not found'], Response::HTTP_NOT_FOUND);
        }

        if ($applicationRepository->isListingOccupied($listing)) {
            return $this->json(['success' => false, 'error' => 'This listing is already occupied'], Response::HTTP_BAD_REQUEST);
        }

        if ($listing->getCreatedBy() && $listing->getCreatedBy()->getId() === $user->getId()) {
            return $this->json(['success' => false, 'error' => 'You cannot apply to your own listing'], Response::HTTP_BAD_REQUEST);
        }

        $existing = $em->getRepository(Application::class)
            ->createQueryBuilder('a')
            ->where('a.listing = :listing')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('listing', $listing)
            ->setParameter('tenant', $user)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existing) {
            return $this->json(['success' => false, 'error' => 'You have already applied for this listing'], Response::HTTP_CONFLICT);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $application = new Application();
        $application->setListing($listing);
        $application->setTenant($user);
        $application->setLandlord($listing->getCreatedBy());
        $application->setStatus('pending');
        $application->setMessage($payload['message'] ?? '');

        $em->persist($application);
        $em->flush();

        if ($listing->getCreatedBy()) {
            $this->notificationService->notifyUser(
                $listing->getCreatedBy(),
                'application_submitted',
                sprintf('Tenant %s has applied for your listing: %s', $user->getName(), $listing->getName()),
                'Application',
                $application->getId()
            );
        }

        $this->activityLogService->logEvent(
            $user,
            'MOBILE_APPLY',
            sprintf('Listing: %s (ID: %d)', $listing->getName(), $listing->getId()),
            'Application #' . $application->getId(),
            'App\\Entity\\Application',
            (string) $application->getId(),
        );

        return $this->json([
            'success' => true,
            'data' => $this->serializeApplication($application, true),
            'message' => 'Application submitted successfully',
        ], Response::HTTP_CREATED);
    }

    #[Route('/payments', name: 'payments', methods: ['GET'])]
    public function payments(PaymentRepository $paymentRepository): JsonResponse
    {
        $user = $this->requireUser();
        $payments = $paymentRepository->findByUser($user);
        $data = array_map(fn (Payment $p) => $this->serializePayment($p, true), $payments);

        return $this->json(['success' => true, 'data' => $data, 'meta' => ['count' => count($data)]]);
    }

    #[Route('/applications/{id}/payments', name: 'payment_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createPayment(
        int $id,
        Request $request,
        ApplicationRepository $applicationRepository,
        EntityManagerInterface $em,
    ): JsonResponse {
        $user = $this->requireUser();
        if (!$this->isGranted('ROLE_TENANT')) {
            return $this->json(['success' => false, 'error' => 'Only tenants can submit payments'], Response::HTTP_FORBIDDEN);
        }

        $application = $applicationRepository->find($id);
        if (!$application || $application->getTenant()?->getId() !== $user->getId()) {
            return $this->json(['success' => false, 'error' => 'Application not found'], Response::HTTP_NOT_FOUND);
        }

        if ($application->getStatus() !== 'approved') {
            return $this->json(['success' => false, 'error' => 'You can only pay for approved applications'], Response::HTTP_BAD_REQUEST);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $payment = new Payment();
        $payment->setApplication($application);
        $payment->setAmount((string) ($payload['amount'] ?? $application->getListing()?->getPrice() ?? '0'));
        $payment->setPaymentMethod($payload['paymentMethod'] ?? 'gcash');
        $payment->setNotes($payload['notes'] ?? null);
        $payment->setStatus('pending');

        $em->persist($payment);
        $em->flush();

        if ($application->getLandlord()) {
            $this->notificationService->notifyUser(
                $application->getLandlord(),
                'payment_submitted',
                sprintf('Tenant %s submitted a payment of PHP %s', $user->getName(), $payment->getAmount()),
                'Payment',
                $payment->getId()
            );
        }

        $this->activityLogService->logEvent(
            $user,
            'MOBILE_PAYMENT',
            sprintf('Payment PHP %s — Application #%d', $payment->getAmount(), $application->getId()),
            $payment->getPaymentMethod() ?? 'mobile',
            'App\\Entity\\Payment',
            (string) $payment->getId(),
        );

        return $this->json([
            'success' => true,
            'data' => $this->serializePayment($payment, true),
            'message' => 'Payment submitted successfully',
        ], Response::HTTP_CREATED);
    }

    /** Record mobile app user action (shows on admin Activity Logs in near real-time). */
    #[Route('/activity', name: 'activity', methods: ['POST'])]
    public function logActivity(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $payload = json_decode($request->getContent(), true) ?? [];

        $action = trim((string) ($payload['action'] ?? ''));
        if ($action === '') {
            return $this->json(['success' => false, 'error' => 'action is required'], Response::HTTP_BAD_REQUEST);
        }

        $targetData = trim((string) ($payload['targetData'] ?? 'Mobile app'));
        $details = isset($payload['details']) ? trim((string) $payload['details']) : null;
        $targetId = isset($payload['targetId']) ? (string) $payload['targetId'] : null;

        $this->activityLogService->logEvent($user, $action, $targetData, $details ?: null, 'App\\Entity\\Mobile', $targetId);

        return $this->json(['success' => true, 'message' => 'Activity logged']);
    }

    #[Route('/notifications', name: 'notifications', methods: ['GET'])]
    public function notifications(NotificationRepository $notificationRepository): JsonResponse
    {
        $user = $this->requireUser();
        $items = $notificationRepository->findByUser($user, 50);
        $data = array_map(fn ($n) => [
            'id' => $n->getId(),
            'type' => $n->getType(),
            'message' => $n->getMessage(),
            'isRead' => $n->isRead(),
            'relatedEntity' => $n->getRelatedEntity(),
            'relatedId' => $n->getRelatedId(),
            'createdAt' => $n->getCreatedAt()->format('c'),
        ], $items);

        return $this->json([
            'success' => true,
            'data' => $data,
            'meta' => ['unread' => $notificationRepository->countUnreadByUser($user)],
        ]);
    }

    #[Route('/notifications/{id}/read', name: 'notification_read', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function markNotificationRead(int $id, NotificationRepository $notificationRepository, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requireUser();
        $notification = $notificationRepository->find($id);
        if (!$notification || $notification->getUser()?->getId() !== $user->getId()) {
            return $this->json(['success' => false, 'error' => 'Notification not found'], Response::HTTP_NOT_FOUND);
        }

        $notification->setIsRead(true);
        $em->flush();

        return $this->json(['success' => true, 'message' => 'Marked as read']);
    }

    #[Route('/notifications/read-all', name: 'notifications_read_all', methods: ['POST'])]
    public function markAllNotificationsRead(NotificationRepository $notificationRepository, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requireUser();
        foreach ($notificationRepository->findUnreadByUser($user) as $notification) {
            $notification->setIsRead(true);
        }
        $em->flush();

        return $this->json(['success' => true, 'message' => 'All notifications marked as read']);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function canAccessApplication(User $user, Application $application): bool
    {
        if ($application->getTenant()?->getId() === $user->getId()) {
            return true;
        }
        if ($application->getLandlord()?->getId() === $user->getId()) {
            return true;
        }

        return $this->isGranted('ROLE_ADMIN');
    }

    /** @return array<string, mixed> */
    private function serializeApplication(Application $application, bool $detailed = false): array
    {
        $listing = $application->getListing();
        $data = [
            'id' => $application->getId(),
            'status' => $application->getStatus(),
            'message' => $application->getMessage(),
            'createdAt' => $application->getCreatedAt()->format('c'),
            'listing' => $listing ? [
                'id' => $listing->getId(),
                'name' => $listing->getName(),
                'price' => $listing->getPrice(),
                'image' => $listing->getImage() ? '/uploads/images/' . $listing->getImage() : null,
            ] : null,
            'landlord' => $application->getLandlord() ? ['name' => $application->getLandlord()->getName()] : null,
        ];

        if ($detailed) {
            $data['updatedAt'] = $application->getUpdatedAt()?->format('c');
            $data['tenant'] = $application->getTenant() ? ['name' => $application->getTenant()->getName()] : null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function serializePayment(Payment $payment, bool $withApplication = false): array
    {
        $data = [
            'id' => $payment->getId(),
            'amount' => $payment->getAmount(),
            'status' => $payment->getStatus(),
            'paymentMethod' => $payment->getPaymentMethod(),
            'notes' => $payment->getNotes(),
            'transactionId' => $payment->getTransactionId(),
            'createdAt' => $payment->getCreatedAt()->format('c'),
            'paidAt' => $payment->getPaidAt()?->format('c'),
            'paymongoCheckoutUrl' => $payment->getPaymongoCheckoutUrl(),
        ];

        if ($withApplication && $payment->getApplication()) {
            $data['application'] = $this->serializeApplication($payment->getApplication());
        }

        return $data;
    }
}
