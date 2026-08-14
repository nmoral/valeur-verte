<?php

declare(strict_types=1);

namespace App\BackOffice\Bouquet;

use App\BackOffice\GrilleCout\Poste;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bouquet du pro persisté (#35), cloisonné par tenant. Adaptateur entre le value
 * object {@see Bouquet} — pur, réutilisé partout — et la base : les postes sont
 * stockés par leur nom en JSONB. Le domaine ne dépend jamais de Doctrine.
 */
#[ORM\Entity(repositoryClass: BouquetRepository::class)]
#[ORM\Table(name: 'bouquet')]
class BouquetEnregistre implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $nom;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $postes;

    private function __construct(Bouquet $bouquet)
    {
        $this->appliquer($bouquet);
    }

    public static function depuis(Bouquet $bouquet): self
    {
        return new self($bouquet);
    }

    public function nom(): string
    {
        return $this->nom;
    }

    public function versBouquet(): Bouquet
    {
        return Bouquet::creer(
            $this->nom,
            ...array_map(Poste::parNom(...), $this->postes),
        );
    }

    public function mettreAJour(Bouquet $bouquet): void
    {
        $this->appliquer($bouquet);
    }

    private function appliquer(Bouquet $bouquet): void
    {
        $this->nom = $bouquet->nom();
        $this->postes = array_map(static fn (Poste $poste): string => $poste->name, $bouquet->postes());
    }
}
