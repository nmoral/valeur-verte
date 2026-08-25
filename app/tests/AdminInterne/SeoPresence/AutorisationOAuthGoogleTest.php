<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoPresence;

use App\AdminInterne\SeoPresence\Google\AutorisationOAuthGoogle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AutorisationOAuthGoogleTest extends TestCase
{
    private function service(MockHttpClient $client, string $id = 'client-123', string $secret = 'secret'): AutorisationOAuthGoogle
    {
        return new AutorisationOAuthGoogle($client, $id, $secret);
    }

    public function testUrlDeConsentementPorteLesBonsParametres(): void
    {
        $url = $this->service(new MockHttpClient())->urlConsentement();

        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        self::assertStringContainsString('client_id=client-123', $url);
        self::assertStringContainsString('response_type=code', $url);
        self::assertStringContainsString('access_type=offline', $url);
        self::assertStringContainsString('prompt=consent', $url);
        self::assertStringContainsString(rawurlencode('https://www.googleapis.com/auth/webmasters.readonly'), $url);
        self::assertStringContainsString(rawurlencode(AutorisationOAuthGoogle::REDIRECT), $url);
    }

    public function testEchangeUnCodeContreUnRefreshToken(): void
    {
        $capture = [];
        $client = new MockHttpClient(static function (string $methode, string $url, array $options) use (&$capture): MockResponse {
            $capture = ['methode' => $methode, 'url' => $url];
            $corps = $options['body'] ?? '';
            self::assertIsString($corps);
            parse_str($corps, $capture['params']);

            return new MockResponse('{"access_token":"ya29.x","refresh_token":"1//new-refresh","expires_in":3600}');
        });

        $refresh = $this->service($client)->echanger('4/0Acode');

        self::assertSame('1//new-refresh', $refresh);
        self::assertSame('POST', $capture['methode']);
        self::assertSame('https://oauth2.googleapis.com/token', $capture['url']);
        self::assertSame('authorization_code', $capture['params']['grant_type'] ?? null);
        self::assertSame('4/0Acode', $capture['params']['code'] ?? null);
        self::assertSame('client-123', $capture['params']['client_id'] ?? null);
        self::assertSame(AutorisationOAuthGoogle::REDIRECT, $capture['params']['redirect_uri'] ?? null);
    }

    public function testNonConfigureOuCodeVideRendNull(): void
    {
        $appels = 0;
        $client = new MockHttpClient(static function () use (&$appels): MockResponse {
            ++$appels;

            return new MockResponse('{}');
        });

        self::assertNull($this->service($client, '')->echanger('4/0Acode'));
        self::assertNull($this->service($client, 'client-123', '')->echanger('4/0Acode'));
        self::assertNull($this->service($client)->echanger(''));
        self::assertSame(0, $appels);
    }

    public function testReponseSansRefreshTokenRendNull(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"access_token":"ya29.x"}'));

        self::assertNull($this->service($client)->echanger('4/0Acode'));
    }

    public function testUneErreurSeDegradeEnNull(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new TransportException('réseau'));

        self::assertNull($this->service($client)->echanger('4/0Acode'));
    }
}
