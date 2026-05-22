<?php

namespace App\Command;

use App\Service\BootstrapDemoDataService;
use App\Service\BootstrapUsersService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:bootstrap-users',
    description: 'Create demo users, categories, and approved listings (Railway / empty DB).',
)]
class BootstrapUsersCommand extends Command
{
    public function __construct(
        private readonly BootstrapDemoDataService $bootstrapDemo,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->bootstrapDemo->ensureDemoEnvironment();
        } catch (\Throwable $e) {
            $io->error('Bootstrap failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        if ($result['users'] !== []) {
            $io->success('Created users: '.implode(', ', $result['users']));
        }
        if ($result['listings'] > 0) {
            $io->success(sprintf('Created %d approved listing(s) and %d categor(ies).', $result['listings'], $result['categories']));
        }
        if ($result['users'] === [] && $result['listings'] === 0) {
            $io->success('Demo environment already present — no changes made.');
        }

        $io->table(
            ['Role', 'Email', 'Password'],
            array_map(
                static fn (array $a) => [$a['role'], $a['email'], $a['password']],
                BootstrapUsersService::demoAccountHints(),
            ),
        );

        return Command::SUCCESS;
    }
}
