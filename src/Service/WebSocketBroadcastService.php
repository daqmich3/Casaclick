<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pushes notification events to the Node WebSocket server (scripts/ws-notification-server.js).
 */
final class WebSocketBroadcastService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $broadcastUrl,
        private readonly string $internalSecret,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->broadcastUrl !== '' && $this->internalSecret !== '';
    }

    public function pushNotification(int $userId, Notification $notification): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $this->httpClient->request('POST', rtrim($this->broadcastUrl, '/') . '/broadcast', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-WS-Secret' => $this->internalSecret,
                ],
                'json' => [
                    'userId' => $userId,
                    'event' => 'new_notification',
                    'notification' => [
                        'id' => $notification->getId(),
                        'type' => $notification->getType(),
                        'message' => $notification->getMessage(),
                        'isRead' => $notification->isRead(),
                        'relatedEntity' => $notification->getRelatedEntity(),
                        'relatedId' => $notification->getRelatedId(),
                        'createdAt' => $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
                    ],
                ],
                'timeout' => 2,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('WebSocket broadcast failed: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
