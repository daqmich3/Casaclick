<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SyncFeedControllerTest extends WebTestCase
{
    public function testSyncFeedRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sync/feed');

        self::assertResponseStatusCodeSame(302);
    }
}
