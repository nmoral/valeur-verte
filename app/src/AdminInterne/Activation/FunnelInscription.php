<?php

declare(strict_types=1);

namespace App\AdminInterne\Activation;

/**
 * État du funnel d'inscription self-service (#111) : combien de visites ont vu
 * l'écran, renseigné leur SIRET, créé un compte — et le taux d'abandon sur l'écran
 * SIRET qui en découle. Comptages en sessions distinctes.
 */
final readonly class FunnelInscription
{
    public function __construct(
        private int $affichees,
        private int $siretRenseigne,
        private int $compteCree,
    ) {
    }

    public function affichees(): int
    {
        return $this->affichees;
    }

    public function siretRenseigne(): int
    {
        return $this->siretRenseigne;
    }

    public function compteCree(): int
    {
        return $this->compteCree;
    }

    /**
     * Abandon = visites ayant vu l'écran sans créer de compte, rapporté aux visites
     * qui l'ont vu. Le numérateur est borné à zéro : une instrumentation qui
     * manquerait un affichage ne doit pas produire un taux négatif.
     */
    public function abandonSiret(): Taux
    {
        return new Taux(max(0, $this->affichees - $this->compteCree), $this->affichees);
    }
}
