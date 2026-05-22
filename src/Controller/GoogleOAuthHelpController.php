<?php

namespace App\Controller;

use App\Service\GoogleOAuthSetup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GoogleOAuthHelpController extends AbstractController
{
    #[Route('/connect/google/help', name: 'app_google_oauth_help', methods: ['GET'])]
    public function help(GoogleOAuthSetup $googleOAuth): Response
    {
        return $this->render('security/google_oauth_help.html.twig', [
            'configured' => $googleOAuth->isConfigured(),
            'client_id' => $googleOAuth->getClientId(),
            'callback_url' => $googleOAuth->getCallbackUrl(),
            'javascript_origin' => $googleOAuth->getJavaScriptOrigin(),
            'console_credentials_url' => 'https://console.cloud.google.com/apis/credentials',
            'console_consent_url' => 'https://console.cloud.google.com/apis/credentials/consent',
        ]);
    }
}
