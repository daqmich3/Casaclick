<?php

namespace App\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Web Google OAuth (KnpU) — client id/secret and callback URL helpers.
 */
class GoogleOAuthSetup
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $defaultUri,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    /** Absolute callback URL Google Console must allow (uses DEFAULT_URI / router default_uri). */
    public function getCallbackUrl(): string
    {
        return $this->urlGenerator->generate(
            'app_google_connect_check',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /** Authorized JavaScript origin (no path). */
    public function getJavaScriptOrigin(): string
    {
        return rtrim($this->defaultUri, '/');
    }

    public function getConfigurationHelp(): string
    {
        if ($this->isConfigured()) {
            return '';
        }

        $lines = [
            'Google Sign-In is not configured on this server.',
            'Set GOOGLE_OAUTH_CLIENT_SECRET in Railway Variables (Web OAuth client secret from Google Cloud Console).',
            'Authorized redirect URI: '.$this->getCallbackUrl(),
            'Authorized JavaScript origin: '.$this->getJavaScriptOrigin(),
        ];

        if ($this->clientId === '') {
            $lines[] = 'GOOGLE_OAUTH_CLIENT_ID is also missing.';
        }

        return implode(' ', $lines);
    }
}
