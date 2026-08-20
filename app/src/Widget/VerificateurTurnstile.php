<?php

declare(strict_types=1);

namespace App\Widget;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Vérification serveur du défi Cloudflare Turnstile (#43, contrôle 2, épic #6).
 *
 * Le défi invisible résolu côté client produit un jeton `cf-turnstile-response` que
 * l'on valide auprès de l'API `siteverify`. Cohérent avec « premier chiffre sans
 * friction » : pas de puzzle en temps normal, seulement une preuve vérifiée côté
 * serveur avant l'estimation / la capture.
 *
 * **Inerte sans secret** : tant que `TURNSTILE_SECRET` n'est pas fourni par
 * l'environnement, la vérification ne fait rien (ni appel, ni rejet) — le contrôle
 * s'active dès que les clés Cloudflare sont configurées, sans changement de code.
 */
final readonly class VerificateurTurnstile
{
    private const string URL_SITEVERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private string $secret,
        private HttpClientInterface $http,
    ) {
    }

    public function verifier(?string $reponse, ?string $ip): void
    {
        if ('' === $this->secret) {
            return;
        }

        if (null === $reponse || '' === $reponse) {
            throw new UnprocessableEntityHttpException();
        }

        $corps = ['secret' => $this->secret, 'response' => $reponse];
        if (null !== $ip) {
            $corps['remoteip'] = $ip;
        }

        $donnees = $this->http->request('POST', self::URL_SITEVERIFY, ['body' => $corps])->toArray(false);

        if (true !== ($donnees['success'] ?? false)) {
            throw new UnprocessableEntityHttpException();
        }
    }
}
