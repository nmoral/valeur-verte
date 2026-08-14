<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Contrôleur de test qui lève une exception non gérée, pour exercer le rendu de
 * production de la page 500 (#197). N'est routé qu'en environnement de test
 * (config/routes/test/erreurs.php) : jamais exposé en prod.
 */
final class ControleurQuiEchoue
{
    public function __invoke(): never
    {
        throw new \RuntimeException('Baticoncept boom : erreur serveur simulée pour le test.');
    }
}
