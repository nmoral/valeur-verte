<?php

declare(strict_types=1);

namespace App\BackOffice\Entreprise;

use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fiche entreprise persistée du pro, alimentée par le SIRET (ONB-2). Cloisonnée
 * par tenant : chaque professionnel ne voit que la sienne. Le statut « Non RGE »
 * est conservé tel quel — segment à observer, jamais un motif de blocage.
 */
#[ORM\Entity(repositoryClass: EntrepriseRepository::class)]
#[ORM\Table(name: 'entreprise')]
#[ORM\Index(name: 'idx_entreprise_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_entreprise_siret', columns: ['siret'])]
class Entreprise implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 14)]
    private string $siret;

    #[ORM\Column(length: 255)]
    private string $raisonSociale;

    #[ORM\Column(length: 255)]
    private string $adresse;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $codeNaf;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $effectif;

    #[ORM\Column(length: 16, enumType: StatutRge::class)]
    private StatutRge $statutRge;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $domainesRge;

    /**
     * @param list<string> $domainesRge
     */
    private function __construct(
        string $siret,
        string $raisonSociale,
        string $adresse,
        ?string $codeNaf,
        ?string $effectif,
        StatutRge $statutRge,
        array $domainesRge,
    ) {
        $this->siret = $siret;
        $this->raisonSociale = $raisonSociale;
        $this->adresse = $adresse;
        $this->codeNaf = $codeNaf;
        $this->effectif = $effectif;
        $this->statutRge = $statutRge;
        $this->domainesRge = $domainesRge;
    }

    public static function depuisFiche(FicheEntreprise $fiche): self
    {
        return new self(
            $fiche->siret()->valeur(),
            $fiche->raisonSociale(),
            $fiche->adresse(),
            '' === $fiche->codeNaf() ? null : $fiche->codeNaf(),
            $fiche->effectif(),
            $fiche->statutRge(),
            $fiche->domainesRge(),
        );
    }

    public function siret(): string
    {
        return $this->siret;
    }

    public function raisonSociale(): string
    {
        return $this->raisonSociale;
    }

    public function adresse(): string
    {
        return $this->adresse;
    }

    public function codeNaf(): ?string
    {
        return $this->codeNaf;
    }

    public function effectif(): ?string
    {
        return $this->effectif;
    }

    public function statutRge(): StatutRge
    {
        return $this->statutRge;
    }

    /**
     * @return list<string>
     */
    public function domainesRge(): array
    {
        return $this->domainesRge;
    }
}
