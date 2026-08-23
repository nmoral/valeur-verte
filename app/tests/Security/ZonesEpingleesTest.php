<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Les zones authentifiées vivent sur leurs sous-domaines (§10.1) : `app.` pour
 * l'espace pro, `admin.` pour l'admin interne. Une route de zone déclarée **sans
 * hôte** répond sur *tous* les hôtes — apex et `widget.` compris — et sort alors du
 * périmètre d'`access_control`, qui ne raisonne que par sous-domaine.
 *
 * Le cas de `widget.` est le plus retors : son firewall est `security: false`, donc
 * même une règle `access_control` par chemin n'y serait pas évaluée. D'où
 * l'épinglage de l'hôte sur la route elle-même — la route ne *matche* pas ailleurs,
 * plutôt que d'y matcher et d'espérer qu'un garde la refuse.
 *
 * Ce test est le garde-fou de la règle : il porte sur la table de routage, donc il
 * couvre aussi les routes qui n'existent pas encore.
 */
final class ZonesEpingleesTest extends WebTestCase
{
    /**
     * Contrôleurs servant une zone authentifiée, et les sous-domaines admis.
     *
     * @var array<string, list<string>>
     */
    private const ZONES = [
        'App\\Controller\\BackOffice\\' => ['app.'],
        'App\\Controller\\AdminInterne\\' => ['admin.'],
        'App\\Controller\\AdminController' => ['admin.'],
        'App\\Controller\\EspaceProController' => ['app.'],
        // Même page pour les deux zones (#206) : chaque zone la protège par son rôle.
        'App\\Controller\\MonCompteController' => ['app.', 'admin.'],
    ];

    public function testChaqueRouteDeZoneAuthentifieeEstEpingleeSurSonSousDomaine(): void
    {
        self::bootKernel();
        $routeur = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $routeur);

        $nues = [];
        foreach ($routeur->getRouteCollection() as $nom => $route) {
            $prefixes = self::sousDomainesAdmis($route->getDefault('_controller'));
            if ([] === $prefixes) {
                continue;
            }

            $hote = $route->getHost();
            foreach ($prefixes as $prefixe) {
                if (str_starts_with($hote, $prefixe)) {
                    continue 2;
                }
            }

            $nues[$nom] = '' === $hote ? '(aucun hôte)' : $hote;
        }

        self::assertSame([], $nues, "Ces routes de zone authentifiée répondent hors de leur sous-domaine :\n".print_r($nues, true));
    }

    /**
     * Le garde structurel dit ce que la table de routage déclare ; celui-ci constate
     * ce qu'un visiteur obtient. Les deux hôtes vérifiés sont ceux qui n'exigent rien :
     * l'apex (aucune règle `access_control` ne le couvre) et `widget.` (firewall
     * `security: false`).
     */
    public function testAucuneRouteDeZoneNeRepondHorsDeSonSousDomaine(): void
    {
        $chemins = ['/audit', '/rapports', '/couts', '/bouquets', '/marque', '/page-publique', '/mon-compte', '/compte/bilan'];

        foreach ($chemins as $chemin) {
            foreach (['valeurverte.localhost', 'widget.valeurverte.localhost'] as $hote) {
                self::ensureKernelShutdown();
                $client = static::createClient();

                $client->request('GET', 'http://'.$hote.$chemin);

                self::assertSame(
                    404,
                    $client->getResponse()->getStatusCode(),
                    \sprintf('%s ne devrait pas exister sur %s.', $chemin, $hote),
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function sousDomainesAdmis(mixed $controleur): array
    {
        if (!\is_string($controleur)) {
            return [];
        }

        foreach (self::ZONES as $marqueur => $prefixes) {
            if (str_starts_with($controleur, $marqueur)) {
                return $prefixes;
            }
        }

        return [];
    }
}
