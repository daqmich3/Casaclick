<?php

namespace App\Command;

use App\Service\GoogleOAuthSetup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:google-oauth-info',
    description: 'Print Google OAuth URLs and config status for Google Cloud Console + Railway.',
)]
class GoogleOAuthInfoCommand extends Command
{
    public function __construct(
        private readonly GoogleOAuthSetup $googleOAuth,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Google Sign-In configuration');

        $io->table(
            ['Setting', 'Value'],
            [
                ['Configured (ID + secret)', $this->googleOAuth->isConfigured() ? 'yes' : 'no'],
                ['Client ID', $this->googleOAuth->getClientId() !== '' ? $this->googleOAuth->getClientId() : '(empty)'],
                ['JavaScript origin', $this->googleOAuth->getJavaScriptOrigin()],
                ['Redirect URI', $this->googleOAuth->getCallbackUrl()],
            ],
        );

        if (!$this->googleOAuth->isConfigured()) {
            $io->warning($this->googleOAuth->getConfigurationHelp());
        }

        $io->section('Google Cloud Console — add under your Web OAuth client');
        $io->listing([
            'Authorized JavaScript origins → '.$this->googleOAuth->getJavaScriptOrigin(),
            'Authorized redirect URIs → '.$this->googleOAuth->getCallbackUrl(),
        ]);

        $io->section('OAuth consent screen (Testing mode)');
        $io->text([
            'Add every Gmail that will sign in under Test users → Add users.',
            'https://console.cloud.google.com/apis/credentials/consent',
        ]);

        $io->section('Railway (web service variables)');
        $io->text([
            'GOOGLE_OAUTH_CLIENT_ID='.$this->googleOAuth->getClientId(),
            'GOOGLE_OAUTH_CLIENT_SECRET=<from Google Console>',
            'DEFAULT_URI='.$this->googleOAuth->getJavaScriptOrigin(),
        ]);

        return Command::SUCCESS;
    }
}
