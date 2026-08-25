<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence\Google;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Jeton d'accès OAuth2 par **compte utilisateur** (#346) : on échange un *refresh
 * token* de longue durée contre un *access token*, sans clé de compte de service.
 *
 * C'est la voie retenue parce que la création de clés de compte de service est
 * bloquée par une règle d'organisation Google (`iam.managed.disableServiceAccountKeyCreation`).
 * Le refresh token s'obtient une fois par un consentement OAuth (client « Desktop »)
 * avec le compte qui a accès à la propriété Search Console ; il est stocké en secret.
 *
 * **Inerte si non configuré** (client id/secret ou refresh token vide → `null`),
 * jeton **mémorisé** le temps de sa validité, dégradation gracieuse sur erreur.
 */
final class JetonOAuthUtilisateurGoogle implements JetonAcces
{
    private const string TOKEN = 'https://oauth2.googleapis.com/token';
    private const int DUREE = 3600;

    private ?string $jetonMemorise = null;
    private ?int $expireA = null;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ClockInterface $horloge,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
    ) {
    }

    public function jeton(): ?string
    {
        if ('' === $this->clientId || '' === $this->clientSecret || '' === $this->refreshToken) {
            return null;
        }

        $maintenant = $this->horloge->now()->getTimestamp();
        if (null !== $this->jetonMemorise && null !== $this->expireA && $maintenant < $this->expireA) {
            return $this->jetonMemorise;
        }

        try {
            $reponse = $this->client->request('POST', self::TOKEN, [
                'body' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $this->refreshToken,
                    'grant_type' => 'refresh_token',
                ],
            ]);
            if (200 !== $reponse->getStatusCode()) {
                return null;
            }
            $donnees = $reponse->toArray(false);
        } catch (ExceptionInterface) {
            return null;
        }

        $jeton = $donnees['access_token'] ?? null;
        if (!\is_string($jeton) || '' === $jeton) {
            return null;
        }

        $duree = \is_int($donnees['expires_in'] ?? null) ? $donnees['expires_in'] : self::DUREE;
        $this->jetonMemorise = $jeton;
        $this->expireA = $maintenant + max(0, $duree - 60);

        return $jeton;
    }
}
