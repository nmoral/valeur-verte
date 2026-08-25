<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Lecture ciblée du modèle publié pour les hubs départementaux (#337). Contrairement
 * à {@see EtudePubliee}, on ne charge pas les contours ni la carte : un hub n'a
 * besoin que des chiffres de son département.
 *
 * Décision produit (#337) : un hub n'existe que si un millésime est publié **et**
 * que le département porte des mailles mesurées — sinon la page serait vide, mauvaise
 * pour le référencement. `pour()` renvoie donc ``null`` dans les deux cas, et le
 * contrôleur répond 404.
 */
final readonly class FichesDepartementales
{
    public function __construct(private LectureModelePublie $lecture)
    {
    }

    public function pour(string $code): ?FicheDepartement
    {
        $publication = $this->lecture->publication();
        if (null === $publication) {
            return null;
        }

        $metriques = MetriquesDepartementales::depuisMailles($this->lecture->mailles());
        $departement = $metriques->pour($code);
        if (null === $departement) {
            return null;
        }

        return new FicheDepartement(
            millesime: $publication->millesime,
            publieLe: $publication->publieLe,
            calculeLe: $publication->calculeLe,
            departement: $departement,
            medianeNationaleEc: $metriques->medianeGainEc(),
            departementsCouverts: $metriques->departementsCouverts(),
        );
    }

    /**
     * Codes des départements réellement mesurés au millésime courant — la liste
     * des hubs qui existent (annuaire de la carte nationale, sitemap). Vide tant
     * qu'aucun millésime n'est publié.
     *
     * @return list<string>
     */
    public function codesMesures(): array
    {
        if (null === $this->lecture->publication()) {
            return [];
        }

        return MetriquesDepartementales::depuisMailles($this->lecture->mailles())->codes();
    }
}
