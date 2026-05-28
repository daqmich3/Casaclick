<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\ActivityLogService;
use App\Service\GoogleIdTokenVerifier;
use App\Service\MobileUserProvisioningService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth', name: 'api_auth_')]
class GoogleAuthController extends AbstractController
{
    public function __construct(
        private readonly GoogleIdTokenVerifier $googleIdTokenVerifier,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $em,
        private readonly ActivityLogService $activityLogService,
        private readonly MobileUserProvisioningService $mobileUserProvisioning,
    ) {
    }

    /**
     * Exchange a native Google Sign-In ID token for a Lexik JWT (mobile app).
     * Creates or links a user by Google account — no separate registration step.
     */
    #[Route('/google', name: 'google', methods: ['POST'])]
    public function google(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];
        $idToken = trim((string) ($body['idToken'] ?? $body['id_token'] ?? ''));

        if ($idToken === '') {
            return $this->json([
                'success' => false,
                'error' => 'idToken is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $googleUser = $this->googleIdTokenVerifier->verify($idToken);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $email = $googleUser['email'];
        $googleId = $googleUser['sub'];
        $name = $googleUser['name'] ?? explode('@', $email)[0];

        $user = $this->em->getRepository(User::class)->findOneBy(['googleId' => $googleId])
            ?? $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

        $isNew = false;

        if (!$user) {
            $isNew = true;
            $role = $this->resolveNewUserRole($body);
            $user = new User();
            $user->setEmail($email);
            $user->setName($name);
            $user->setGoogleId($googleId);
            $user->setRoles([$role]);
            $user->setEmailVerified(true);
            $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
            $this->em->persist($user);
            $this->mobileUserProvisioning->ensureTenantProfile($user);
        } else {
            if (!$user->isEnabled()) {
                return $this->json([
                    'success' => false,
                    'error' => 'Your account has been disabled. Please contact support.',
                ], Response::HTTP_FORBIDDEN);
            }

            $user->setGoogleId($googleId);
            if (!$user->getName() && $name) {
                $user->setName($name);
            }
            $user->setEmailVerified(true);
        }

        $this->em->flush();

        if ($isNew) {
            $this->activityLogService->logEvent(
                $user,
                'MOBILE_REGISTER',
                sprintf('Registered via Google (%s)', $email),
                $user->getPrimaryRole(),
                'App\\Entity\\User',
                (string) $user->getId(),
            );
        }

        $jwt = $this->jwtManager->create($user);

        return $this->json([
            'success' => true,
            'token' => $jwt,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'roles' => $user->getRoles(),
                'emailVerified' => $user->isEmailVerified(),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function resolveNewUserRole(array $body): string
    {
        $role = (string) ($body['role'] ?? 'ROLE_TENANT');
        if (!in_array($role, ['ROLE_TENANT', 'ROLE_LANDLORD', 'ROLE_STAFF'], true)) {
            return 'ROLE_TENANT';
        }

        return $role;
    }
}
