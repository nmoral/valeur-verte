<?php

declare(strict_types=1);

namespace App\Widget;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Adaptateur s'appuyant sur la Base Adresse Nationale (api-adresse.data.gouv.fr),
 * publique et sans authentification (#287).
 *
 * L'appel part de **notre serveur** : le navigateur du visiteur ne parle qu'à
 * notre origine (CSP `connect-src 'self'`, {@see CspWidget}), et ni son IP ni
 * l'adresse qu'il tape ne sont exposées à un tiers depuis une page qui annonce
 * une collecte anonyme et sans partage (#90).
 *
 * Toute défaillance (réseau, statut non 200, JSON illisible) dégrade vers une
 * liste vide : la page reste utilisable, sans message d'erreur technique.
 */
final readonly class RechercheAdresseViaBan implements RechercheAdresse
{
    /**
     * Une poignée de propositions suffit à choisir : au-delà, la liste devient un
     * catalogue à lire plutôt qu'un raccourci.
     */
    private const int LIMITE = 5;

    public function __construct(
        private HttpClientInterface $banAdresse,
    ) {
    }

    public function suggerer(string $requete): array
    {
        $suggestions = [];
        foreach ($this->features($requete) as $feature) {
            $proprietes = \is_array($feature) ? ($feature['properties'] ?? null) : null;
            if (!\is_array($proprietes)) {
                continue;
            }

            $adresse = AdresseSuggeree::creer(
                $this->texte($proprietes['label'] ?? null),
                $this->texte($proprietes['citycode'] ?? null),
            );

            // Sans commune, aucun coefficient : on ne propose que ce qui peut être chiffré.
            if ('' === $adresse->libelle() || '' === $adresse->codeInsee()) {
                continue;
            }

            $suggestions[] = $adresse;
        }

        return $suggestions;
    }

    /**
     * @return list<mixed>
     */
    private function features(string $requete): array
    {
        try {
            $reponse = $this->banAdresse->request('GET', 'search/', [
                'query' => ['q' => $requete, 'limit' => self::LIMITE, 'autocomplete' => 1],
            ]);

            if (200 !== $reponse->getStatusCode()) {
                return [];
            }

            $donnees = $reponse->toArray(false);
        } catch (ExceptionInterface) {
            return [];
        }

        $features = $donnees['features'] ?? null;

        return \is_array($features) ? array_values($features) : [];
    }

    private function texte(mixed $valeur): string
    {
        return \is_string($valeur) ? $valeur : '';
    }
}
