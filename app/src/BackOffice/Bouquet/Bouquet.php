<?php

declare(strict_types=1);

namespace App\BackOffice\Bouquet;

use App\BackOffice\GrilleCout\Poste;

/**
 * Bouquet de travaux : combinaison nommée de postes, réutilisée en rendez-vous.
 *
 * C'est l'unité de sélection du mode audit (§9.2). Objet immuable : chaque
 * opération renvoie une nouvelle instance.
 */
final readonly class Bouquet
{
    /**
     * @param list<Poste> $postes
     */
    private function __construct(
        private string $nom,
        private array $postes,
    ) {
    }

    public static function creer(string $nom, Poste ...$postes): self
    {
        $nom = trim($nom);
        if ('' === $nom) {
            throw new \InvalidArgumentException('Le nom du bouquet ne peut pas être vide.');
        }

        $uniques = self::dedupliquer($postes);
        if ([] === $uniques) {
            throw new \InvalidArgumentException('Un bouquet doit contenir au moins un poste.');
        }

        return new self($nom, $uniques);
    }

    public static function renovationAmpleurStandard(): self
    {
        // ONB-3 : couvre tous les postes pour qu'un rapport complet soit produisible
        // dès la création du compte, sans configuration.
        return new self("Rénovation d'ampleur standard", Poste::cases());
    }

    public static function isolationSeule(): self
    {
        return self::creer('Isolation seule', Poste::Murs, Poste::Combles, Poste::PlanchersBas, Poste::Menuiseries);
    }

    public static function chauffageSeul(): self
    {
        return self::creer('Chauffage seul', Poste::Chauffage, Poste::Ecs, Poste::Vmc);
    }

    public function nom(): string
    {
        return $this->nom;
    }

    /**
     * @return list<Poste>
     */
    public function postes(): array
    {
        return $this->postes;
    }

    public function contient(Poste $poste): bool
    {
        return \in_array($poste, $this->postes, true);
    }

    public function avecPoste(Poste $poste): self
    {
        if ($this->contient($poste)) {
            return $this;
        }

        return new self($this->nom, [...$this->postes, $poste]);
    }

    public function sansPoste(Poste $poste): self
    {
        $restants = array_values(array_filter(
            $this->postes,
            static fn (Poste $candidat): bool => $candidat !== $poste,
        ));
        if ([] === $restants) {
            throw new \InvalidArgumentException('Un bouquet doit conserver au moins un poste.');
        }

        return new self($this->nom, $restants);
    }

    public function renomme(string $nom): self
    {
        $nom = trim($nom);
        if ('' === $nom) {
            throw new \InvalidArgumentException('Le nom du bouquet ne peut pas être vide.');
        }

        return new self($nom, $this->postes);
    }

    /**
     * @param array<array-key, Poste> $postes
     *
     * @return list<Poste>
     */
    private static function dedupliquer(array $postes): array
    {
        $uniques = [];
        foreach ($postes as $poste) {
            if (!\in_array($poste, $uniques, true)) {
                $uniques[] = $poste;
            }
        }

        return $uniques;
    }
}
