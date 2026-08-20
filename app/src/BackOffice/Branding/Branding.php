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
 */
#[ORM\Entity(repositoryClass: BrandingRepository::class)]
#[ORM\Table(name: 'branding')]
class Branding implements TenantAwareInterface
{
    use TenantAwareTrait;

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
        $this->numeroRge = self::normaliser($numeroRge);
        $this->telephone = self::normaliser($telephone);
        $this->mentionsLegales = self::normaliser($mentionsLegales);
        $this->logoUrl = self::normaliser($logoUrl);
        if (null !== $this->logoUrl) {
            UrlLogo::valider($this->logoUrl);
        }
        $this->nom = self::normaliser($nom);
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

    private static function normaliser(?string $valeur): ?string
    {
        if (null === $valeur) {
            return null;
        }

        $valeur = trim($valeur);

        return '' === $valeur ? null : $valeur;
    }
}
