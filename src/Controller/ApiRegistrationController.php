<?php

namespace App\Controller;

use App\Entity\User;
use App\Exception\MailNotConfiguredException;
use App\Service\EmailVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
class ApiRegistrationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private EmailVerificationService $emailVerificationService,
        private ValidatorInterface $validator,
    ) {
    }

    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        if (!isset($data['username'], $data['email'], $data['password'])) {
            return $this->json([
                'success' => false,
                'message' => 'Username, email, and password are required',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (strlen((string) $data['username']) < 3) {
            return $this->json([
                'success' => false,
                'message' => 'Username must be at least 3 characters long',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->json([
                'success' => false,
                'message' => 'Invalid email address',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (strlen((string) $data['password']) < 6) {
            return $this->json([
                'success' => false,
                'message' => 'Password must be at least 6 characters long',
            ], Response::HTTP_BAD_REQUEST);
        }

        $existingUser = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['name' => $data['username']]);

        if ($existingUser) {
            return $this->json([
                'success' => false,
                'message' => 'Username already exists',
            ], Response::HTTP_CONFLICT);
        }

        $existingEmail = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['email' => $data['email']]);

        if ($existingEmail) {
            return $this->json([
                'success' => false,
                'message' => 'Email already registered',
            ], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setName((string) $data['username']);
        $user->setEmail((string) $data['email']);
        $user->setPassword($this->passwordHasher->hashPassword($user, (string) $data['password']));
        $user->setRoles(['ROLE_USER']);

        $mailDisabled = $this->emailVerificationService->isMailTransportDisabled();
        if ($mailDisabled) {
            $user->setEmailVerified(true);
            $user->setVerificationToken(null);
        } else {
            $verificationToken = $this->emailVerificationService->generateVerificationToken();
            $user->setVerificationToken($verificationToken);
            $user->setEmailVerified(false);
        }

        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }

            return $this->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $errorMessages,
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $emailSent = true;
        $mailError = null;
        if (!$mailDisabled) {
            $verificationUrl = $this->emailVerificationService->generateVerifyEmailUrl(
                (string) $user->getVerificationToken(),
            );
            try {
                $this->emailVerificationService->sendVerificationEmail($user, $verificationUrl);
            } catch (MailNotConfiguredException $e) {
                $emailSent = false;
                $mailError = $e->getMessage();
                $user->setEmailVerified(true);
                $user->setVerificationToken(null);
                $this->entityManager->flush();
            } catch (\Throwable $e) {
                $emailSent = false;
            }
        }

        return $this->json([
            'success' => true,
            'message' => $mailDisabled || (!$emailSent && $user->isEmailVerified())
                ? 'Registration successful. You can sign in now.'
                : ($emailSent
                ? 'Registration successful. Please check your email to verify your account.'
                : ($mailError ?? 'Registration successful, but the verification email could not be sent. Configure MAILER_DSN and use resend verification.')),
            'emailSent' => $emailSent,
            'user' => [
                'id' => $user->getId(),
                'username' => $user->getName(),
                'email' => $user->getEmail(),
                'isVerified' => $user->isEmailVerified(),
                'roles' => $user->getRoles(),
            ],
        ], Response::HTTP_CREATED);
    }
}
