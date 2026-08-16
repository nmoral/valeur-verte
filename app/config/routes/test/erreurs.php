<?php

declare(strict_types=1);

use App\Tests\Support\ControleurQuiEchoue;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * Routes exposées uniquement en environnement de test (le MicroKernelTrait importe
 * config/routes/{env}/*). Elles servent à exercer le rendu des pages d'erreur de
 * production sans jamais exister en prod : ici, un endpoint qui lève une exception
 * pour déclencher le gabarit 500 (#197).
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('test_erreur_serveur', '/_test/erreur-serveur')
        ->controller(ControleurQuiEchoue::class);
};
