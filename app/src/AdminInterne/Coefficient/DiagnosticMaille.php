<?php

declare(strict_types=1);

namespace App\AdminInterne\Coefficient;

/**
 * Diagnostic d'aberration d'une maille (#45) : liste les raisons pour lesquelles
 * un coefficient doit remonter en supervision avant d'être affiché à un client.
 * Le gain E→C est comparé en log (coef_C − coef_E).
 */
final readonly class DiagnosticMaille
{
    /**
     * @param list<string> $raisons
     */
    private function __construct(private array $raisons)
    {
    }

    public static function pour(
        float $erreurMedianePct,
        float $gainEcLog,
        ?string $alerteBord,
        SeuilsSupervision $seuils,
    ): self {
        $raisons = [];

        if ($erreurMedianePct > $seuils->erreurMedianeMaxPct()) {
            $raisons[] = 'erreur_mediane_elevee';
        }

        if ($gainEcLog <= 0.0) {
            $raisons[] = 'gain_ec_incoherent';
        } elseif ($gainEcLog > $seuils->gainEcMaxLog()) {
            $raisons[] = 'gain_ec_hors_borne';
        }

        if (null !== $alerteBord && '' !== trim($alerteBord)) {
            $raisons[] = 'rupture_bord';
        }

        return new self($raisons);
    }

    public function estAberrante(): bool
    {
        return [] !== $this->raisons;
    }

    /**
     * @return list<string>
     */
    public function raisons(): array
    {
        return $this->raisons;
    }
}
