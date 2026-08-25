<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Correspondance entre le code d'un département et son *slug* d'URL (#337, hubs
 * départementaux SEO). Le slug est un **contrat permanent** : une URL indexée ne
 * doit jamais changer sous peine de perdre son référencement. On les fige donc à
 * la main, lisibles et sans abréviation (« territoire-de-belfort », pas
 * « terr-de-belfort »), plutôt que de les dériver d'un slugifieur dont l'algo
 * pourrait évoluer.
 *
 * Le slug est la **clé** : les libellés des slugs sont toujours des chaînes non
 * numériques, là où un tableau code => slug verrait PHP transformer « 21 » en clé
 * entière (même piège que {@see NomsDepartements}).
 */
final class RepertoireDepartements
{
    /**
     * @var array<string, string> slug d'URL => code département, en ordre de code
     */
    private const CODE_PAR_SLUG = [
        'ain' => '01', 'aisne' => '02', 'allier' => '03', 'alpes-de-haute-provence' => '04',
        'hautes-alpes' => '05', 'alpes-maritimes' => '06', 'ardeche' => '07', 'ardennes' => '08',
        'ariege' => '09', 'aube' => '10', 'aude' => '11', 'aveyron' => '12', 'bouches-du-rhone' => '13',
        'calvados' => '14', 'cantal' => '15', 'charente' => '16', 'charente-maritime' => '17',
        'cher' => '18', 'correze' => '19', 'corse-du-sud' => '2A', 'haute-corse' => '2B',
        'cote-d-or' => '21', 'cotes-d-armor' => '22', 'creuse' => '23', 'dordogne' => '24',
        'doubs' => '25', 'drome' => '26', 'eure' => '27', 'eure-et-loir' => '28', 'finistere' => '29',
        'gard' => '30', 'haute-garonne' => '31', 'gers' => '32', 'gironde' => '33', 'herault' => '34',
        'ille-et-vilaine' => '35', 'indre' => '36', 'indre-et-loire' => '37', 'isere' => '38',
        'jura' => '39', 'landes' => '40', 'loir-et-cher' => '41', 'loire' => '42', 'haute-loire' => '43',
        'loire-atlantique' => '44', 'loiret' => '45', 'lot' => '46', 'lot-et-garonne' => '47',
        'lozere' => '48', 'maine-et-loire' => '49', 'manche' => '50', 'marne' => '51',
        'haute-marne' => '52', 'mayenne' => '53', 'meurthe-et-moselle' => '54', 'meuse' => '55',
        'morbihan' => '56', 'moselle' => '57', 'nievre' => '58', 'nord' => '59', 'oise' => '60',
        'orne' => '61', 'pas-de-calais' => '62', 'puy-de-dome' => '63', 'pyrenees-atlantiques' => '64',
        'hautes-pyrenees' => '65', 'pyrenees-orientales' => '66', 'bas-rhin' => '67', 'haut-rhin' => '68',
        'rhone' => '69', 'haute-saone' => '70', 'saone-et-loire' => '71', 'sarthe' => '72',
        'savoie' => '73', 'haute-savoie' => '74', 'paris' => '75', 'seine-maritime' => '76',
        'seine-et-marne' => '77', 'yvelines' => '78', 'deux-sevres' => '79', 'somme' => '80',
        'tarn' => '81', 'tarn-et-garonne' => '82', 'var' => '83', 'vaucluse' => '84', 'vendee' => '85',
        'vienne' => '86', 'haute-vienne' => '87', 'vosges' => '88', 'yonne' => '89',
        'territoire-de-belfort' => '90', 'essonne' => '91', 'hauts-de-seine' => '92',
        'seine-saint-denis' => '93', 'val-de-marne' => '94', 'val-d-oise' => '95',
    ];

    /**
     * Code du département servi par ce slug, ou ``null`` si le slug est inconnu.
     */
    public function code(string $slug): ?string
    {
        return self::CODE_PAR_SLUG[$slug] ?? null;
    }

    /**
     * Slug d'URL d'un code de département, ou ``null`` si le code est inconnu.
     */
    public function slug(string $code): ?string
    {
        $slug = array_search($code, self::CODE_PAR_SLUG, true);

        return false === $slug ? null : $slug;
    }

    public function nom(string $code): string
    {
        return NomsDepartements::pour($code);
    }

    /**
     * Tous les codes de département connus, en ordre de code.
     *
     * @return list<string>
     */
    public function tous(): array
    {
        return array_values(self::CODE_PAR_SLUG);
    }

    /**
     * Annuaire prêt à l'affichage (carte nationale) et à générer (sitemap) pour un
     * ensemble de codes : chaque entrée porte code, nom et slug, triée par nom. Un
     * code sans slug connu est ignoré — l'annuaire ne pointe jamais vers un hub
     * inexistant.
     *
     * @param list<string> $codes
     *
     * @return list<array{code: string, nom: string, slug: string}>
     */
    public function annuaire(array $codes): array
    {
        $entrees = [];
        foreach ($codes as $code) {
            $slug = $this->slug($code);
            if (null === $slug) {
                continue;
            }
            $entrees[] = ['code' => $code, 'nom' => $this->nom($code), 'slug' => $slug];
        }

        usort($entrees, static fn (array $a, array $b): int => $a['nom'] <=> $b['nom']);

        return $entrees;
    }
}
