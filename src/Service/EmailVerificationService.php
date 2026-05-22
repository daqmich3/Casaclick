<?php

namespace App\Service;

use App\Entity\User;
use App\Exception\MailNotConfiguredException;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class EmailVerificationService
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
        #[Autowire('%env(MAILER_DEFAULT_EMAIL)%')] private readonly string $mailerDefaultEmail,
        #[Autowire('%env(DEFAULT_URI)%')] private readonly string $defaultUri,
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $mailerDsn,
    ) {
    }

    /**
     * Public base URL for links in emails. Uses DEFAULT_URI so verification works on a phone or another
     * device (not http://127.0.0.1, which only works on the same machine that runs the server).
     */
    public function generateVerifyEmailUrl(string $token): string
    {
        $base = rtrim($this->defaultUri, '/');
        $path = $this->urlGenerator->generate(
            'app_verify_email',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_PATH
        );

        return $base.$path;
    }

    public function generateVerificationToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function verifyToken(string $token): ?User
    {
        return $this->userRepository->findOneBy(['verificationToken' => $token]);
    }

    /**
     * @param User $user User must have email set. If $verificationUrl is null, a new token is generated and stored on the user.
     */
    public function sendVerificationEmail(User $user, ?string $verificationUrl = null): void
    {
        if ($this->isMailTransportDisabled()) {
            throw new MailNotConfiguredException(
                'Mail is not configured: MAILER_DSN in .env is null://null (no real delivery). '
                .'Copy .env.local on this machine or set MAILER_DSN to your provider (e.g. Brevo: brevo+smtp://SMTP_LOGIN:SMTP_KEY@default). '
                .'Restart PHP after changing env.'
            );
        }

        if ($verificationUrl === null) {
            $token = $this->generateVerificationToken();
            $user->setVerificationToken($token);

            $verificationUrl = $this->generateVerifyEmailUrl($token);
        }

        $context = [
            'user' => $user,
            'verifyUrl' => $verificationUrl,
            'expiresAt' => new \DateTimeImmutable('+24 hours'),
        ];

        $email = (new TemplatedEmail())
            ->from(Address::create($this->mailerDefaultEmail))
            ->to($user->getEmail())
            ->replyTo(Address::create($this->mailerDefaultEmail))
            ->subject('Verify your CasaClick account')
            ->htmlTemplate('emails/verification.html.twig')
            ->textTemplate('emails/verification.txt.twig')
            ->context($context);

        $this->mailer->send($email);

        $this->logger->info('Verification email sent via mailer.', [
            'to' => $user->getEmail(),
            'from' => $this->mailerDefaultEmail,
        ]);
    }

    /** True when Symfony would use the null transport (discards mail with no error). */
    public function isMailTransportDisabled(): bool
    {
        $dsn = trim($this->mailerDsn);

        return '' === $dsn || 1 === preg_match('#^null://#i', $dsn);
    }
}
