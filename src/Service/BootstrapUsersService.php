<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Idempotent demo accounts for empty databases (local fixtures / Railway first deploy).
 */
class BootstrapUsersService
{
    /** @var list<array{email: string, name: string, roles: list<string>, password: string}> */
    private const DEMO_USERS = [
        ['email' => 'admin@example.com', 'name' => 'Admin', 'roles' => ['ROLE_ADMIN'], 'password' => 'admin1234'],
        ['email' => 'landlord@example.com', 'name' => 'Landlord', 'roles' => ['ROLE_LANDLORD'], 'password' => 'landlord3333'],
        ['email' => 'tenant@example.com', 'name' => 'Tenant', 'roles' => ['ROLE_TENANT'], 'password' => 'tenant2222'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @return list<string> emails that were created
     */
    public function ensureDemoUsers(): array
    {
        $repo = $this->em->getRepository(User::class);
        $created = [];

        foreach (self::DEMO_USERS as $spec) {
            if ($repo->findOneBy(['email' => $spec['email']]) !== null) {
                continue;
            }

            $user = new User();
            $user->setEmail($spec['email']);
            $user->setName($spec['name']);
            $user->setRoles($spec['roles']);
            $user->setPassword($this->passwordHasher->hashPassword($user, $spec['password']));
            $user->setEmailVerified(true);
            $user->setIsEnabled(true);

            $this->em->persist($user);
            $created[] = $spec['email'];
        }

        if ($created !== []) {
            $this->em->flush();
        }

        return $created;
    }

    /** @return list<array{email: string, password: string, role: string}> */
    public static function demoAccountHints(): array
    {
        return [
            ['email' => 'admin@example.com', 'password' => 'admin1234', 'role' => 'Admin'],
            ['email' => 'landlord@example.com', 'password' => 'landlord3333', 'role' => 'Landlord'],
            ['email' => 'tenant@example.com', 'password' => 'tenant2222', 'role' => 'Tenant'],
        ];
    }
}
