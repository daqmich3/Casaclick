<?php

namespace App\Command;

use App\Service\BootstrapUsersService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:bootstrap-users',
    description: 'Create demo login accounts (admin, landlord, tenant) if they do not exist yet.',
)]
class BootstrapUsersCommand extends Command
{
    public function __construct(
        private readonly BootstrapUsersService $bootstrapUsers,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $created = $this->bootstrapUsers->ensureDemoUsers();
        } catch (\Throwable $e) {
            $io->error('Bootstrap failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        if ($created === []) {
            $io->success('Demo accounts already exist — no changes made.');
        } else {
            $io->success('Created demo accounts: '.implode(', ', $created));
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
