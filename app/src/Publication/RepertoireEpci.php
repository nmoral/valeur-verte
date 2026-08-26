<?php

declare(strict_types=1);

namespace App\Publication;

use Doctrine\DBAL\Connection;

/**
 * Répertoire des EPCI lu dans le schéma « data » en lecture seule (décision D6),
 * pour le hub EPCI de la Vague 2 SEO (#338, #363) : il résout une URL
 * ``/valeur-verte/epci/{slug}`` vers son EPCI et retrouve un EPCI par son code
 * (sens inverse, pour reconstruire une URL / le maillage).
 *
 * Contrairement à {@see RepertoireDepartements} (liste figée de 96 départements,
 * slug figé à la main), les EPCI sont nombreux (~1250) et leurs noms bougent au fil
 * des fusions : leur nom vit en base (``data.epci`` #366) et le slug est **recalculé**
 * depuis le nom par {@see Slug}, comme pour les communes. La résolution charge donc
 * le référentiel et compare les slugs ; l'ordre par ``code_epci`` départage un
 * éventuel homonyme de façon déterministe (le plus petit SIREN l'emporte).
 */
final readonly class RepertoireEpci
{
    private const SELECT_BASE = 'SELECT code_epci, nom FROM data.epci';

    public function __construct(private Connection $connexion)
    {
    }

    public function resoudre(string $slug): ?Epci
    {
        foreach ($this->tous() as $epci) {
            if ($epci->slug() === $slug) {
                return $epci;
            }
        }

        return null;
    }

    /**
     * Sens inverse : l'EPCI d'un ``code_epci`` (SIREN), ou ``null`` s'il n'est pas
     * au référentiel. Sert à reconstruire l'URL du hub (maillage montant).
     */
    public function pour(string $codeEpci): ?Epci
    {
        $ligne = $this->connexion->fetchAssociative(
            self::SELECT_BASE.' WHERE code_epci = :code',
            ['code' => $codeEpci],
        );

        return false === $ligne ? null : self::depuisLigne($ligne);
    }

    /**
     * Le référentiel entier, ordonné par ``code_epci`` (désambiguïsation stable des
     * homonymes de slug).
     *
     * @return list<Epci>
     */
    private function tous(): array
    {
        $lignes = $this->connexion->fetchAllAssociative(self::SELECT_BASE.' ORDER BY code_epci');

        $epcis = [];
        foreach ($lignes as $ligne) {
            $epcis[] = self::depuisLigne($ligne);
        }

        return $epcis;
    }

    /**
     * @param array<string, mixed> $ligne
     */
    private static function depuisLigne(array $ligne): Epci
    {
        return new Epci(
            codeEpci: \is_string($ligne['code_epci']) ? trim($ligne['code_epci']) : '',
            nom: \is_string($ligne['nom']) ? $ligne['nom'] : '',
        );
    }
}
