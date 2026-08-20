<?php

declare(strict_types=1);

namespace App\BackOffice\Branding;

use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Marque du professionnel, appliquée au PDF, au lien de relance et à la page
 * publique (§9.2). La couleur principale est un value object stocké en hexadécimal.
 *
 * Tout est optionnel hormis la couleur : un PDF reste produisible sans logo ni
 * configuration (ONB-4), avec la couleur de marque par défaut.
 *
 * Le branding s'affiche sur une page publique hébergée **sur notre origine**
 * (#267, D9) : ses valeurs sont validées **à la frontière**, jamais en interne.
 * L'URL de logo passe par {@see UrlLogo} (anti-SSRF/XSS) et les champs texte sont
 * bornés en longueur — `mentionsLegales` n'a aucune borne de colonne (TEXT), et
 * un dépassement sur les autres partirait en erreur SQL (500) au lieu d'un refus
 * lisible par le pro.
 */
#[ORM\Entity(repositoryClass: BrandingRepository::class)]
#[ORM\Table(name: 'branding')]
class Branding implements TenantAwareInterface
{
    use TenantAwareTrait;

    public const int LONGUEUR_MAX_NOM = 255;
    public const int LONGUEUR_MAX_TELEPHONE = 32;
    public const int LONGUEUR_MAX_NUMERO_RGE = 64;
    /** Un bloc de mentions légales, pas un document : la colonne TEXT n'en dit rien. */
    public const int LONGUEUR_MAX_MENTIONS_LEGALES = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 7)]
    private string $couleurHex;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $numeroRge;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $telephone;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $mentionsLegales;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoUrl;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nom;

    private function __construct(
        Couleur $couleurPrincipale,
        ?string $numeroRge,
        ?string $telephone,
        ?string $mentionsLegales,
        ?string $logoUrl,
        ?string $nom,
    ) {
        $this->couleurHex = $couleurPrincipale->hex();
        $this->numeroRge = self::borner(self::normaliser($numeroRge), self::LONGUEUR_MAX_NUMERO_RGE, 'numéro RGE');
        $this->telephone = self::borner(self::normaliser($telephone), self::LONGUEUR_MAX_TELEPHONE, 'téléphone');
        $this->mentionsLegales = self::borner(self::normaliser($mentionsLegales), self::LONGUEUR_MAX_MENTIONS_LEGALES, 'mentions légales');
        $this->logoUrl = self::normaliser($logoUrl);
        if (null !== $this->logoUrl) {
            UrlLogo::valider($this->logoUrl);
        }
        $this->nom = self::borner(self::normaliser($nom), self::LONGUEUR_MAX_NOM, 'nom');
    }

    public static function parDefaut(): self
    {
        return new self(Couleur::depuisHex('#0E4A5C'), null, null, null, null, null);
    }

    public static function creer(
        Couleur $couleurPrincipale,
        ?string $numeroRge,
        ?string $telephone,
        ?string $mentionsLegales,
        ?string $logoUrl,
        ?string $nom = null,
    ): self {
        return new self($couleurPrincipale, $numeroRge, $telephone, $mentionsLegales, $logoUrl, $nom);
    }

    public function couleurPrincipale(): Couleur
    {
        return Couleur::depuisHex($this->couleurHex);
    }

    public function numeroRge(): ?string
    {
        return $this->numeroRge;
    }

    public function telephone(): ?string
    {
        return $this->telephone;
    }

    public function mentionsLegales(): ?string
    {
        return $this->mentionsLegales;
    }

    public function logoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function nom(): ?string
    {
        return $this->nom;
    }

    /**
     * Borne en **caractères**, pas en octets : « é » pèse deux octets, compter en
     * octets amputerait une saisie légitime.
     *
     * @throws \InvalidArgumentException si la valeur dépasse la borne du champ
     */
    private static function borner(?string $valeur, int $maximum, string $champ): ?string
    {
        if (null !== $valeur && mb_strlen($valeur) > $maximum) {
            throw new \InvalidArgumentException(\sprintf('Le champ « %s » est limité à %d caractères.', $champ, $maximum));
        }

        return $valeur;
    }

    private static function normaliser(?string $valeur): ?string
    {
        if (null === $valeur) {
            return null;
        }

        $valeur = trim($valeur);

        return '' === $valeur ? null : $valeur;
    }
}
