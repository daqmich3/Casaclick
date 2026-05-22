<?php

namespace App\Controller;

use App\Entity\User;
use App\Exception\MailNotConfiguredException;
use App\Form\RegistrationFormType;
use App\Service\EmailVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        EmailVerificationService $verificationService,
        LoggerInterface $logger,
    ): Response {
        if ($this->getUser()) {
            if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF')) {
                return $this->redirectToRoute('app_dashboard');
            }
            return $this->redirectToRoute('app_product_index');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && !$form->isValid()) {
            $errorMessages = [];
            foreach ($form->getErrors(true) as $error) {
                $errorMessages[] = $error->getMessage();
            }
            $errorMessages = array_values(array_unique($errorMessages));
            if ($errorMessages !== []) {
                $this->addFlash('error', 'Registration could not complete: '.implode(' ', $errorMessages));
                $logger->notice('Registration validation failed.', ['errors' => $errorMessages]);
            } else {
                $logger->warning('Registration form submitted but invalid with no error messages (possible CSRF/session issue).');
                $this->addFlash(
                    'error',
                    'The form could not be submitted securely. Reload this page, then try again. Use the same address you used to open the site (stick to either 127.0.0.1 or localhost, not both).'
                );
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            // Get selected role from form - ensure only one role is assigned
            $selectedRole = $form->get('role')->getData();
            
            // Ensure only one role is set (form has multiple => false, but double-check)
            if (is_array($selectedRole)) {
                $selectedRole = !empty($selectedRole) ? $selectedRole[0] : 'ROLE_TENANT';
            }
            
            // Validate role is one of the allowed roles
            $allowedRoles = ['ROLE_LANDLORD', 'ROLE_TENANT'];
            if (!in_array($selectedRole, $allowedRoles, true)) {
                $selectedRole = 'ROLE_TENANT'; // Default to tenant if invalid
            }
            
            // Set only one role
            $user->setRoles([$selectedRole]);
            
            $hashedPassword = $passwordHasher->hashPassword(
                $user,
                $form->get('plainPassword')->getData()
            );
            $user->setPassword($hashedPassword);

            $em->persist($user);

            // Railway / demo: no real mailer — allow sign-in immediately after register.
            if ($verificationService->isMailTransportDisabled()) {
                $user->setEmailVerified(true);
                $user->setVerificationToken(null);
                $em->flush();
                $this->addFlash(
                    'success',
                    'Account created. You can sign in now. (Email auto-verified — outgoing mail is not configured on this server.)'
                );

                return $this->redirectToRoute('app_login');
            }

            $user->setEmailVerified(false);
            $em->flush();

            try {
                // Sends email to the user’s address with a link to /verify-email/{token}; login stays blocked until verified (UserChecker).
                $verificationService->sendVerificationEmail($user);
                $em->flush(); // persist verification token
            } catch (MailNotConfiguredException $e) {
                $user->setEmailVerified(true);
                $user->setVerificationToken(null);
                $em->flush();
                $this->addFlash(
                    'success',
                    'Account created. You can sign in now. (Verification email could not be sent.)'
                );

                return $this->redirectToRoute('app_login');
            } catch (\Throwable $e) {
                $logger->error('Registration: verification email failed.', ['exception' => $e]);
                // Token was set on the user before send(); keep the account and persist the token for /resend-verification.
                $em->flush();
                $this->addFlash(
                    'warning',
                    'Your account was created, but we could not send the verification email. '
                    .'You must verify your email before signing in — use “Didn’t get the verification email?” on the login page once mail is working.'
                );

                return $this->redirectToRoute('app_login');
            }

            $this->addFlash(
                'success',
                'Check your email — we sent a verification link. Open it to confirm your address; you cannot sign in until your email is verified.'
            );

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }
}


