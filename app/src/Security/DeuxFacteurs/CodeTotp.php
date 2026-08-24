<?php

declare(strict_types=1);

namespace App\Security\DeuxFacteurs;

/**
 * Code TOTP (RFC 6238) — second facteur de l'admin interne (#312).
 *
 * HMAC-SHA1 du numéro de pas de 30 secondes, troncature dynamique, 6 chiffres :
 * c'est ce que calcule toute application d'authentification (Google
 * Authenticator, Aegis, 1Password). Écrit à la main plutôt qu'emprunté — la RFC
 * publie ses vecteurs de test, qui vérifient l'implémentation ligne à ligne
 * (voir CodeTotpTest) ; une dépendance de plus dans le chemin d'authentification
 * se justifierait par un algorithme qu'on ne saurait pas vérifier.
 *
 * SHA-1 est ici l'algorithme **du protocole**, pas un choix : c'est le seul que
 * toutes les applications d'authentification implémentent. Il n'est pas utilisé
 * pour sa résistance aux collisions mais comme HMAC, usage pour lequel il reste
 * sûr.
 */
final readonly class CodeTotp
{
    public const int PAS_SECONDES = 30;
    private const int CHIFFRES = 6;

    private string $secretBinaire;

    /**
     * @param string $secretBase32 secret partagé, tel qu'il est transmis à
     *                             l'application d'authentification
     */
    public function __construct(string $secretBase32)
    {
        $this->secretBinaire = Base32::decoder($secretBase32);
    }

    public function pour(\DateTimeImmutable $instant): string
    {
        // Compteur sur 8 octets big-endian : c'est le message HMAC de la RFC.
        $compteur = pack('J', $this->pas($instant));
        $empreinte = hash_hmac('sha1', $compteur, $this->secretBinaire, true);

        // Troncature dynamique (RFC 4226 §5.3) : les 4 bits de poids faible du
        // dernier octet désignent où lire les 31 bits du code.
        $decalage = \ord($empreinte[19]) & 0x0F;
        $tronque = ((\ord($empreinte[$decalage]) & 0x7F) << 24)
            | (\ord($empreinte[$decalage + 1]) << 16)
            | (\ord($empreinte[$decalage + 2]) << 8)
            | \ord($empreinte[$decalage + 3]);

        return str_pad(
            (string) ($tronque % 10 ** self::CHIFFRES),
            self::CHIFFRES,
            '0',
            \STR_PAD_LEFT,
        );
    }

    /**
     * Numéro de la fenêtre de 30 secondes. Mémorisé après usage pour refuser le
     * rejeu d'un code encore dans sa fenêtre.
     */
    public function pas(\DateTimeImmutable $instant): int
    {
        return intdiv($instant->getTimestamp(), self::PAS_SECONDES);
    }
}
