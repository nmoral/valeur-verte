<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoPresence;

use App\AdminInterne\SeoPresence\Google\JetonCompteServiceGoogle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class JetonCompteServiceGoogleTest extends TestCase
{
    /** @var array{private: string, public: string} */
    private array $cles;

    protected function setUp(): void
    {
        $ressource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($ressource);
        openssl_pkey_export($ressource, $prive);
        $details = openssl_pkey_get_details($ressource);
        self::assertIsArray($details);
        $this->cles = ['private' => $prive, 'public' => (string) $details['key']];
    }

    private function service(MockHttpClient $client, string $email = 'svc@projet.iam.gserviceaccount.com', ?string $clePrivee = null): JetonCompteServiceGoogle
    {
        return new JetonCompteServiceGoogle(
            $client,
            new MockClock('2026-08-25 04:00:00'),
            $email,
            $clePrivee ?? $this->cles['private'],
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
        self::assertNull($this->service($client, 'svc@x', '')->jeton());
        self::assertSame(0, $appels, 'Aucun appel réseau quand la source n\'est pas configurée.');
    }

    public function testEchangeUnJwtSigneContreUnAccessToken(): void
    {
        $assertion = null;
        $urlAppelee = null;
        $client = new MockHttpClient(function (string $methode, string $url, array $options) use (&$assertion, &$urlAppelee): MockResponse {
            $urlAppelee = $url;
            parse_str((string) $options['body'], $params);
            $assertion = $params['assertion'] ?? null;

            return new MockResponse('{"access_token":"ya29.test","expires_in":3600,"token_type":"Bearer"}');
        });

        $jeton = $this->service($client)->jeton();

        self::assertSame('ya29.test', $jeton);
        self::assertSame('https://oauth2.googleapis.com/token', $urlAppelee);

        // Le JWT est signé par la clé privée et vérifiable par la clé publique.
        self::assertIsString($assertion);
        [$enteteB64, $chargeB64, $signatureB64] = explode('.', $assertion);
        $signature = base64_decode(strtr($signatureB64, '-_', '+/'), true);
        self::assertNotFalse($signature);
        $verifie = openssl_verify($enteteB64.'.'.$chargeB64, $signature, $this->cles['public'], \OPENSSL_ALGO_SHA256);
        self::assertSame(1, $verifie, 'La signature RS256 doit être valide.');

        $charge = json_decode((string) base64_decode(strtr($chargeB64, '-_', '+/'), true), true);
        self::assertIsArray($charge);
        self::assertSame('svc@projet.iam.gserviceaccount.com', $charge['iss']);
        self::assertSame('https://oauth2.googleapis.com/token', $charge['aud']);
        self::assertStringContainsString('webmasters', (string) $charge['scope']);
        self::assertSame(strtotime('2026-08-25 04:00:00'), $charge['iat']);
        self::assertGreaterThan($charge['iat'], $charge['exp']);
    }

    public function testLeJetonEstMemorise(): void
    {
        $appels = 0;
        $client = new MockHttpClient(function () use (&$appels): MockResponse {
            ++$appels;

            return new MockResponse('{"access_token":"ya29.test","expires_in":3600}');
        });

        $service = $this->service($client);
        $service->jeton();
        $service->jeton();

        self::assertSame(1, $appels, 'Le jeton valide est réutilisé sans réémettre de requête.');
    }

    public function testUneErreurDeTokenRendNull(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new TransportException('réseau'));

        self::assertNull($this->service($client)->jeton());
    }
}
