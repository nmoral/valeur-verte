<?php

declare(strict_types=1);

namespace App\BackOffice\Entreprise;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Adapter s'appuyant sur l'API Recherche d'Entreprises (annuaire-entreprises,
 * data.gouv). Publique et sans authentification : toute défaillance (réseau,
 * statut non 200, JSON illisible) dégrade silencieusement vers null.
 */
final class RechercheEntrepriseViaApiGouv implements RechercheEntreprise
{
    public function __construct(
        private readonly HttpClientInterface $rechercheEntreprises,
    ) {
    }

    public function parSiret(Siret $siret): ?IdentiteEntreprise
    {
        $resultat = $this->premierResultat($siret);
        if (null === $resultat) {
            return null;
        }

        $etablissement = $this->etablissementCorrespondant($resultat, $siret);
        if (null === $etablissement || 'F' === ($etablissement['etat_administratif'] ?? null)) {
            return null;
        }

        $raisonSociale = $this->texte($resultat['nom_raison_sociale'] ?? null)
            ?: $this->texte($resultat['nom_complet'] ?? null);
        if ('' === $raisonSociale) {
            return null;
        }

        return IdentiteEntreprise::creer(
            $raisonSociale,
            $this->texte($etablissement['adresse'] ?? null),
            $this->texte($resultat['activite_principale'] ?? null),
            $this->libelleEffectif($resultat['tranche_effectif_salarie'] ?? null),
        );
    }

    /**
     * @return array<mixed>|null
     */
    private function premierResultat(Siret $siret): ?array
    {
        try {
            $reponse = $this->rechercheEntreprises->request('GET', 'search', [
                'query' => ['q' => $siret->valeur(), 'page' => 1, 'per_page' => 1],
            ]);

            if (200 !== $reponse->getStatusCode()) {
                return null;
            }

            $donnees = $reponse->toArray(false);
        } catch (ExceptionInterface) {
            return null;
        }

        $resultats = $donnees['results'] ?? null;
        $premier = \is_array($resultats) ? ($resultats[0] ?? null) : null;

        return \is_array($premier) ? $premier : null;
    }

    /**
     * @param array<mixed> $resultat
     *
     * @return array<mixed>|null
     */
    private function etablissementCorrespondant(array $resultat, Siret $siret): ?array
    {
        $candidats = $resultat['matching_etablissements'] ?? [];
        if (\is_array($candidats)) {
            foreach ($candidats as $etablissement) {
                if (\is_array($etablissement) && $siret->valeur() === ($etablissement['siret'] ?? null)) {
                    return $etablissement;
                }
            }
        }

        $siege = $resultat['siege'] ?? null;
        if (\is_array($siege) && $siret->valeur() === ($siege['siret'] ?? null)) {
            return $siege;
        }

        return null;
    }

    private function texte(mixed $valeur): string
    {
        return \is_string($valeur) ? trim($valeur) : '';
    }

    private function libelleEffectif(mixed $code): ?string
    {
        if (!\is_string($code)) {
            return null;
        }

        return match ($code) {
            '00' => '0 salarié',
            '01' => '1 ou 2 salariés',
            '02' => '3 à 5 salariés',
            '03' => '6 à 9 salariés',
            '11' => '10 à 19 salariés',
            '12' => '20 à 49 salariés',
            '21' => '50 à 99 salariés',
            '22' => '100 à 199 salariés',
            '31' => '200 à 249 salariés',
            '32' => '250 à 499 salariés',
            '41' => '500 à 999 salariés',
            '42' => '1 000 à 1 999 salariés',
            '51' => '2 000 à 4 999 salariés',
            '52' => '5 000 à 9 999 salariés',
            '53' => '10 000 salariés et plus',
            default => null,
        };
    }
}
