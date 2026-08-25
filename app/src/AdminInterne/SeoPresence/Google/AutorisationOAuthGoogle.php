<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence\Google;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Amorçage OAuth « une fois » (#346) : produit l'URL de consentement puis échange le
 * code d'autorisation contre un **refresh token**, en utilisant **exactement** le
 * `client_id`/`client_secret` configurés — ce qui évite le piège du refresh token émis
 * par un autre client (erreur `unauthorized_client`). Le refresh token obtenu se colle
 * ensuite dans `GSC_OAUTH_REFRESH_TOKEN`.
 *
 * Flux « installed app » en boucle locale : la redirection pointe vers
 * {@see self::REDIRECT} ; le navigateur n'a rien à servir, l'opérateur recopie le
 * paramètre `code` depuis la barre d'adresse.
 */
final readonly class AutorisationOAuthGoogle
{
    public const string REDIRECT = 'http://localhost';

    private const string AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const string TOKEN = 'https://oauth2.googleapis.com/token';
    private const string SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public function __construct(
        private HttpClientInterface $client,
        private string $clientId,
        private string $clientSecret,
    ) {
    }

    public function estConfigure(): bool
    {
        return '' !== $this->clientId && '' !== $this->clientSecret;
    }

    public function urlConsentement(): string
    {
        return self::AUTH.'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    public function echanger(string $code): ?string
    {
        if (!$this->estConfigure() || '' === $code) {
            return null;
        }

        try {
            $reponse = $this->client->request('POST', self::TOKEN, ['body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => self::REDIRECT,
            ]]);
            if (200 !== $reponse->getStatusCode()) {
                return null;
            }
            $donnees = $reponse->toArray(false);
        } catch (ExceptionInterface) {
            return null;
        }

        $refresh = $donnees['refresh_token'] ?? null;

        return \is_string($refresh) && '' !== $refresh ? $refresh : null;
    }
}
