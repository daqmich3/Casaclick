<?php

namespace App\EventListener;

use App\Entity\ActivityLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * SecurityEventListener - Logs user authentication events
 * 
 * REQUIRED ACTIONS BEING LOGGED:
 * ✅ User login (all roles: Admin, Landlord, Tenant)
 * ✅ User logout (all roles: Admin, Landlord, Tenant)
 */
class SecurityEventListener implements EventSubscriberInterface
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public static function getSubscribedEvents()
    {
        return [
            InteractiveLoginEvent::class => 'onLogin',
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLogin(InteractiveLoginEvent $event): void
    {
        $user = $event->getAuthenticationToken()->getUser();
        if (!is_object($user)) {
            return;
        }

        $log = new ActivityLog();
        $log->setAction('LOGIN');
        $log->setUser($user);
        $log->setUsername($user->getEmail() ?? $user->getName() ?? 'Unknown');
        $primaryRole = method_exists($user, 'getPrimaryRole') ? $user->getPrimaryRole() : 'ROLE_TENANT';
        $log->setRole($primaryRole);
        $log->setTargetEntity(get_class($user));
        if (method_exists($user, 'getId')) {
            $log->setTargetId((string)$user->getId());
        }
        $username = $user->getEmail() ?? $user->getName() ?? 'Unknown';
        $userId = method_exists($user, 'getId') ? $user->getId() : null;
        $prefix = $primaryRole === 'ROLE_TENANT' ? 'Customer login: ' : 'User login: ';
        $log->setTargetData($prefix . $username . ($userId !== null ? ' (ID: ' . $userId . ')' : ''));

        try {
            $this->em->persist($log);
            $this->em->flush();
        } catch (\Throwable) {
            // Never block login if activity_log is missing or DB is unavailable.
        }
    }

    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        if (!$token) {
            return;
        }
        $user = $token->getUser();
        if (!is_object($user)) {
            return;
        }

        $log = new ActivityLog();
        $log->setAction('LOGOUT');
        $log->setUser($user);
        $log->setUsername($user->getEmail() ?? $user->getName() ?? 'Unknown');
        $primaryRole = method_exists($user, 'getPrimaryRole') ? $user->getPrimaryRole() : 'ROLE_TENANT';
        $log->setRole($primaryRole);
        $log->setTargetEntity(get_class($user));
        if (method_exists($user, 'getId')) {
            $log->setTargetId((string)$user->getId());
        }
        $username = $user->getEmail() ?? $user->getName() ?? 'Unknown';
        $userId = method_exists($user, 'getId') ? $user->getId() : null;
        $prefix = $primaryRole === 'ROLE_TENANT' ? 'Customer logout: ' : 'User logout: ';
        $log->setTargetData($prefix . $username . ($userId !== null ? ' (ID: ' . $userId . ')' : ''));

        try {
            $this->em->persist($log);
            $this->em->flush();
        } catch (\Throwable) {
        }
    }
}
