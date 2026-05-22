<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\LiveSyncRevisionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Session-authenticated live sync for all logged-in web pages (local + Railway).
 * Mobile uses the same logic via GET /api/mobile/sync/revision (JWT).
 */
#[Route('/sync')]
final class SyncFeedController extends AbstractController
{
    #[Route('/feed', name: 'app_sync_feed', methods: ['GET'])]
    public function feed(LiveSyncRevisionService $liveSyncRevision): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(array_merge(
            ['success' => true],
            $liveSyncRevision->buildForUser($user),
        ));
    }
}
