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
        self::assertIsString($prive);
        $details = openssl_pkey_get_details($ressource);
        self::assertIsArray($details);
        $public = $details['key'];
        self::assertIsString($public);
        $this->cles = ['private' => $prive, 'public' => $public];
    }

    private function decoderBase64url(string $b64): string
    {
        $decode = base64_decode(strtr($b64, '-_', '+/'), true);
        self::assertIsString($decode);

        return $decode;
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
        $client = new MockHttpClient(static function (string $methode, string $url, array $options) use (&$assertion, &$urlAppelee): MockResponse {
            $urlAppelee = $url;
            $corps = $options['body'] ?? '';
            self::assertIsString($corps);
            parse_str($corps, $params);
            $assertion = $params['assertion'] ?? null;

            return new MockResponse('{"access_token":"ya29.test","expires_in":3600,"token_type":"Bearer"}');
        });

        $jeton = $this->service($client)->jeton();

        self::assertSame('ya29.test', $jeton);
        self::assertSame('https://oauth2.googleapis.com/token', $urlAppelee);

        // Le JWT est signé par la clé privée et vérifiable par la clé publique.
        self::assertIsString($assertion);
        [$enteteB64, $chargeB64, $signatureB64] = explode('.', $assertion);
        $verifie = openssl_verify($enteteB64.'.'.$chargeB64, $this->decoderBase64url($signatureB64), $this->cles['public'], \OPENSSL_ALGO_SHA256);
        self::assertSame(1, $verifie, 'La signature RS256 doit être valide.');

        $charge = json_decode($this->decoderBase64url($chargeB64), true);
        self::assertIsArray($charge);
        self::assertSame('svc@projet.iam.gserviceaccount.com', $charge['iss']);
        self::assertSame('https://oauth2.googleapis.com/token', $charge['aud']);
        self::assertIsString($charge['scope']);
        self::assertStringContainsString('webmasters', $charge['scope']);
        self::assertSame(strtotime('2026-08-25 04:00:00'), $charge['iat']);
        self::assertGreaterThan($charge['iat'], $charge['exp']);
    }

    public function testLeJetonEstMemorise(): void
    {
        $appels = 0;
        $client = new MockHttpClient(static function () use (&$appels): MockResponse {
            ++$appels;

            return new MockResponse('{"access_token":"ya29.test","expires_in":3600}');
        });

        $service = $this->service($client);
        $service->jeton();
        $service->jeton();

        self::assertSame(1, $appels, 'Le jeton valide est réutilisé sans réémettre de requête.');
    }

    public function testUnJetonExpireEstReemis(): void
    {
        $appels = 0;
        $client = new MockHttpClient(static function () use (&$appels): MockResponse {
            ++$appels;

            return new MockResponse('{"access_token":"ya29.test","expires_in":3600}');
        });
        $horloge = new MockClock('2026-08-25 04:00:00');
        $service = new JetonCompteServiceGoogle($client, $horloge, 'svc@x', $this->cles['private']);

        $service->jeton();
        $horloge->sleep(4000); // au-delà de la fenêtre de validité (3600 - marge 60 s)
        $service->jeton();

        self::assertSame(2, $appels, 'Un jeton expiré doit être réémis.');
    }

    public function testUneErreurDeTokenRendNull(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new TransportException('réseau'));

        self::assertNull($this->service($client)->jeton());
    }
}
