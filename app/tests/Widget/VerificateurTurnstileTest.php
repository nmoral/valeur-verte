<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Widget\VerificateurTurnstile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Vérification Cloudflare Turnstile côté serveur (#43, contrôle 2, épic #6).
 *
 * Le défi (invisible) résolu côté client produit un jeton `cf-turnstile-response`,
 * validé ici auprès de l'API `siteverify`. Sans secret configuré, la vérification
 * est **inerte** (aucun appel, aucun rejet) : le contrôle s'active uniquement quand
 * les clés Cloudflare sont fournies par l'environnement.
 */
final class VerificateurTurnstileTest extends TestCase
{
    public function testSansSecretConfigureLaVerificationEstInerte(): void
    {
        $http = new MockHttpClient([]);
        $verificateur = new VerificateurTurnstile('', $http);

        $verificateur->verifier('peu-importe', '203.0.113.1');

        self::assertSame(0, $http->getRequestsCount(), 'Aucun appel réseau sans secret.');
    }

    public function testUnDefiResoluEstAccepte(): void
    {
        $http = new MockHttpClient([new MockResponse((string) json_encode(['success' => true]))]);
        $verificateur = new VerificateurTurnstile('secret-cloudflare', $http);

        $verificateur->verifier('jeton-turnstile-valide', '203.0.113.1');

        self::assertSame(1, $http->getRequestsCount());
    }

    public function testUnDefiEchoueEstRejete(): void
    {
        $http = new MockHttpClient([new MockResponse((string) json_encode(['success' => false, 'error-codes' => ['invalid-input-response']]))]);
        $verificateur = new VerificateurTurnstile('secret-cloudflare', $http);

        $this->expectException(UnprocessableEntityHttpException::class);
        $verificateur->verifier('jeton-invalide', '203.0.113.1');
    }

    public function testUneReponseVideEstRejeteeSansAppelReseau(): void
    {
        $http = new MockHttpClient([]);
        $verificateur = new VerificateurTurnstile('secret-cloudflare', $http);

        try {
            $verificateur->verifier('', '203.0.113.1');
            self::fail('Une réponse vide doit être rejetée.');
        } catch (UnprocessableEntityHttpException) {
            // attendu
        }

        self::assertSame(0, $http->getRequestsCount(), 'Réponse vide : court-circuit, pas d\'appel.');
    }
}
