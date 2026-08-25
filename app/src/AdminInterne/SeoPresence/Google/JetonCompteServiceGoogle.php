<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence\Google;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Jeton d'accès OAuth2 pour un **compte de service** Google (#346), sans SDK : on
 * signe soi-même une assertion JWT RS256 (en-tête + revendications signés à la clé
 * privée PEM du compte de service via `openssl`), qu'on échange contre un
 * `access_token` au token endpoint. Le jeton est **mémorisé** le temps de sa validité
 * (une commande de collecte enchaîne plusieurs appels API sur le même run).
 *
 * **Inerte si non configuré** (email ou clé vide → `null`) : la présence externe est
 * une source optionnelle qui n'existe qu'une fois les secrets provisionnés au VPS ;
 * son absence ne doit jamais faire échouer quoi que ce soit (patron
 * {@see \App\Widget\VerificateurTurnstile}). Toute erreur réseau/HTTP se dégrade en `null`.
 */
final class JetonCompteServiceGoogle implements JetonAcces
{
    private const string AUD = 'https://oauth2.googleapis.com/token';
    private const string SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const int DUREE = 3600;

    private ?string $jetonMemorise = null;
    private ?int $expireA = null;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ClockInterface $horloge,
        private readonly string $email,
        private readonly string $clePrivee,
    ) {
    }

    public function jeton(): ?string
    {
        if ('' === $this->email || '' === $this->clePrivee) {
            return null;
        }

        $maintenant = $this->horloge->now()->getTimestamp();
        if (null !== $this->jetonMemorise && null !== $this->expireA && $maintenant < $this->expireA) {
            return $this->jetonMemorise;
        }

        $assertion = $this->signerAssertion($maintenant);
        if (null === $assertion) {
            return null;
        }

        try {
            $reponse = $this->client->request('POST', self::AUD, [
                'body' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
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
        // Marge de 60 s : ne pas présenter un jeton au bord de l'expiration.
        $this->expireA = $maintenant + max(0, $duree - 60);

        return $jeton;
    }

    private function signerAssertion(int $maintenant): ?string
    {
        $entete = $this->base64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $charge = $this->base64url((string) json_encode([
            'iss' => $this->email,
            'scope' => self::SCOPE,
            'aud' => self::AUD,
            'iat' => $maintenant,
            'exp' => $maintenant + self::DUREE,
        ]));

        $signature = '';
        if (!openssl_sign($entete.'.'.$charge, $signature, $this->clePrivee, \OPENSSL_ALGO_SHA256) || !\is_string($signature)) {
            return null;
        }

        return $entete.'.'.$charge.'.'.$this->base64url($signature);
    }

    private function base64url(string $donnees): string
    {
        return rtrim(strtr(base64_encode($donnees), '+/', '-_'), '=');
    }
}
