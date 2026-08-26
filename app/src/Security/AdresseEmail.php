<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Adresse email d'un compte (#331), sous ses deux formes.
 *
 * - {@see valeur()} — la forme **de connexion** : l'adresse telle qu'elle est
 *   stockée, envoyée et affichée, toujours en minuscules. Les domaines sont
 *   insensibles à la casse par le DNS et aucun fournisseur grand public ne
 *   distingue la casse de la partie locale : garder la forme saisie n'apporterait
 *   rien et obligerait à traîner deux formes partout.
 * - {@see canonique()} — la forme **d'identité**, celle qui porte l'unicité.
 *   Elle réduit les variantes qui aboutissent dans la même boîte : l'alias
 *   `+tag`, que tous les fournisseurs grand public traitent comme une
 *   sous-adresse. Sans elle, `pro+1@`, `pro+2@`… valent autant de comptes — donc
 *   autant d'essais gratuits.
 *
 * Les deux formes coexistent : le pro relève son courrier sur son alias, mais il
 * n'a qu'une identité.
 *
 * L'adresse est **validée à la construction** (`FILTER_VALIDATE_EMAIL`, comme la
 * saisie d'inscription le faisait à la main) : la partie locale et le domaine
 * existent donc toujours, et le découpage n'a pas de cas dégénéré à traiter.
 */
final class AdresseEmail implements \Stringable
{
    /**
     * Domaines qui n'en sont qu'un seul, du point de vue de la boîte servie.
     * Liste **en dur** et non configurable : elle bouge à l'échelle de la
     * décennie, et une règle d'identité rangée en base est une règle qu'on ne
     * relit plus jamais.
     *
     * @var array<string, string>
     */
    private const array DOMAINES_ALIAS = ['googlemail.com' => 'gmail.com'];

    /**
     * Domaines qui **ignorent les points** de la partie locale. La règle n'est
     * surtout pas générique : sur un domaine d'entreprise, « jean.dupont@ » et
     * « jeandupont@ » sont deux personnes, et les confondre empêcherait un
     * collègue de s'inscrire. Liste explicite, donc, et en dur pour la même
     * raison que {@see DOMAINES_ALIAS} — elle s'applique **après** la réécriture
     * des alias de domaine, si bien qu'un « googlemail » en bénéficie aussi.
     *
     * @var list<string>
     */
    private const array DOMAINES_SANS_POINTS = ['gmail.com'];

    /**
     * Domaines de messagerie **jetable** (boîtes éphémères, sans inscription).
     * Une adresse sur l'un d'eux naît **sans essai** (#343) — même traitement que
     * le compte recréé après effacement (#332) : on ne refuse pas l'inscription,
     * on retire seulement la gratuité. Le filet reste complémentaire du mur de
     * vérification du 2ᵉ rapport (rendement décroissant, jeu du chat et de la
     * souris), d'où une liste **en dur** — non configurable pour la même raison
     * que {@see DOMAINES_ALIAS} : une règle rangée en base est une règle qu'on ne
     * relit plus jamais. On y ajoute une entrée quand un domaine se banalise.
     *
     * @var list<string>
     */
    private const array DOMAINES_JETABLES = [
        'yopmail.com',
        'yopmail.fr',
        'yopmail.net',
        'mailinator.com',
        '10minutemail.com',
        'guerrillamail.com',
        'guerrillamail.info',
        'sharklasers.com',
        'temp-mail.org',
        'tempmail.com',
        'throwawaymail.com',
        'trashmail.com',
        'getnada.com',
        'maildrop.cc',
        'dispostable.com',
        'fakeinbox.com',
        'mailnesia.com',
        'jetable.org',
    ];

    private function __construct(
        private readonly string $locale,
        private readonly string $domaine,
    ) {
    }

    /**
     * @throws AdresseEmailInvalide
     */
    public static function depuis(string $saisie): self
    {
        $normalisee = self::normaliser($saisie);

        if (false === filter_var($normalisee, \FILTER_VALIDATE_EMAIL)) {
            throw AdresseEmailInvalide::pour($saisie);
        }

        // Découpe sur le **dernier** arobase : seul celui-là sépare la partie
        // locale du domaine (une partie locale entre guillemets peut en contenir).
        $separateur = strrpos($normalisee, '@');
        \assert(false !== $separateur); // garanti par FILTER_VALIDATE_EMAIL

        return new self(substr($normalisee, 0, $separateur), substr($normalisee, $separateur + 1));
    }

    public static function estValide(string $saisie): bool
    {
        return false !== filter_var(self::normaliser($saisie), \FILTER_VALIDATE_EMAIL);
    }

    public function valeur(): string
    {
        return $this->locale.'@'.$this->domaine;
    }

    public function canonique(): string
    {
        $domaine = self::DOMAINES_ALIAS[$this->domaine] ?? $this->domaine;
        $locale = self::sansAlias($this->locale);

        if (\in_array($domaine, self::DOMAINES_SANS_POINTS, true)) {
            $locale = str_replace('.', '', $locale);
        }

        return $locale.'@'.$domaine;
    }

    /**
     * Le domaine relève-t-il d'une messagerie jetable ? Décidé sur le domaine
     * **de connexion** (déjà en minuscules et sans espaces), non sur la forme
     * canonique : yopmail/mailinator ne sont pas des alias connus, et la liste
     * vise le domaine tel qu'il est saisi.
     */
    public function estJetable(): bool
    {
        return \in_array($this->domaine, self::DOMAINES_JETABLES, true);
    }

    public function __toString(): string
    {
        return $this->valeur();
    }

    private static function normaliser(string $saisie): string
    {
        return mb_strtolower(trim($saisie));
    }

    /**
     * Retire la sous-adresse « +tag ». Une partie locale qui n'est **que** l'alias
     * (« +tag@… », adresse valide) est laissée telle quelle : la vider ferait
     * collisionner toutes les adresses du domaine sur une même identité.
     */
    private static function sansAlias(string $locale): string
    {
        $plus = strpos($locale, '+');

        return false === $plus || 0 === $plus ? $locale : substr($locale, 0, $plus);
    }
}
