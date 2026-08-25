<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoPresence;

use App\AdminInterne\SeoPresence\Google\JetonOAuthUtilisateurGoogle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class JetonOAuthUtilisateurGoogleTest extends TestCase
{
    private function service(MockHttpClient $client, string $clientId = 'client-123', string $secret = 'secret', string $refresh = 'refresh-abc', ?MockClock $horloge = null): JetonOAuthUtilisateurGoogle
    {
        return new JetonOAuthUtilisateurGoogle(
            $client,
            $horloge ?? new MockClock('2026-08-25 04:00:00'),
            $clientId,
            $secret,
            $refresh,
        );
    }

    public function testNonConfigureRendNullSansAppel(): void
    {
        $appels = 0;
        $client = new MockHttpClient(static function () use (&$appels): MockResponse {
            ++$appels;

            return new MockResponse('{}');
        });

        self::assertNull($this->service($client, '')->jeton());
        self::assertNull($this->service($client, 'client-123', '')->jeton());
        self::assertNull($this->service($client, 'client-123', 'secret', '')->jeton());
        self::assertSame(0, $appels, 'Aucun appel réseau si un secret OAuth manque.');
    }

    public function testEchangeLeRefreshTokenContreUnAccessToken(): void
    {
        $capture = [];
        $urlAppelee = null;
        $client = new MockHttpClient(static function (string $methode, string $url, array $options) use (&$capture, &$urlAppelee): MockResponse {
            $urlAppelee = $url;
            $corps = $options['body'] ?? '';
            self::assertIsString($corps);
            parse_str($corps, $capture);

            return new MockResponse('{"access_token":"ya29.oauth","expires_in":3600,"token_type":"Bearer"}');
        });

        self::assertSame('ya29.oauth', $this->service($client)->jeton());
        self::assertSame('https://oauth2.googleapis.com/token', $urlAppelee);
        self::assertSame('refresh_token', $capture['grant_type'] ?? null);
        self::assertSame('client-123', $capture['client_id'] ?? null);
        self::assertSame('secret', $capture['client_secret'] ?? null);
        self::assertSame('refresh-abc', $capture['refresh_token'] ?? null);
    }

    public function testLeJetonEstMemorise(): void
    {
        $appels = 0;
        $client = new MockHttpClient(static function () use (&$appels): MockResponse {
            ++$appels;

            return new MockResponse('{"access_token":"ya29.oauth","expires_in":3600}');
        });

        $service = $this->service($client);
        $service->jeton();
        $service->jeton();

        self::assertSame(1, $appels);
    }

    public function testUnJetonExpireEstReemis(): void
    {
        $appels = 0;
        $client = new MockHttpClient(static function () use (&$appels): MockResponse {
            ++$appels;

            return new MockResponse('{"access_token":"ya29.oauth","expires_in":3600}');
        });
        $horloge = new MockClock('2026-08-25 04:00:00');

        $service = $this->service($client, horloge: $horloge);
        $service->jeton();
        $horloge->sleep(4000);
        $service->jeton();

        self::assertSame(2, $appels);
    }

    public function testUneErreurSeDegradeEnNull(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new TransportException('réseau'));

        self::assertNull($this->service($client)->jeton());
    }
}
