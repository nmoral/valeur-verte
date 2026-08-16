<?php

declare(strict_types=1);

namespace App\AdminInterne\Coefficient;

use Doctrine\ORM\Mapping as ORM;

/**
 * Seuils d'alerte de la supervision des coefficients (#45) : au-delà, une maille
 * est signalée avant qu'un pro ne l'affiche. Le gain E→C est un effet log
 * (coef_C − coef_E), pas un montant € — la conversion € est un chantier ultérieur.
 *
 * Réglage éditable dans l'admin, persisté en une ligne unique. Défauts alignés
 * sur les invariants moteur : 30 % (plafond dégradé INV-4) et 0,70 (gain_dpe_ec_max INV-2).
 */
#[ORM\Entity(repositoryClass: SeuilsSupervisionRepository::class)]
#[ORM\Table(name: 'seuils_supervision')]
class SeuilsSupervision
{
    private const ERREUR_MEDIANE_MAX_DEFAUT = 30.0;
    private const GAIN_EC_MAX_LOG_DEFAUT = 0.70;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private readonly float $erreurMedianeMaxPct;

    #[ORM\Column]
    private readonly float $gainEcMaxLog;

    private function __construct(float $erreurMedianeMaxPct, float $gainEcMaxLog)
    {
        if ($erreurMedianeMaxPct <= 0.0 || $gainEcMaxLog <= 0.0) {
            throw new \InvalidArgumentException('Les seuils de supervision doivent être strictement positifs.');
        }

        $this->erreurMedianeMaxPct = $erreurMedianeMaxPct;
        $this->gainEcMaxLog = $gainEcMaxLog;
    }

    public static function parDefaut(): self
    {
        return new self(self::ERREUR_MEDIANE_MAX_DEFAUT, self::GAIN_EC_MAX_LOG_DEFAUT);
    }

    public static function de(float $erreurMedianeMaxPct, float $gainEcMaxLog): self
    {
        return new self($erreurMedianeMaxPct, $gainEcMaxLog);
    }

    public function erreurMedianeMaxPct(): float
    {
        return $this->erreurMedianeMaxPct;
    }

    public function gainEcMaxLog(): float
    {
        return $this->gainEcMaxLog;
    }
}
