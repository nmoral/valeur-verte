<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exploitation\Sante\VerificateurSante;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Santé applicative (#313). La sonde du conteneur et la surveillance externe ne
 * lisent que le code HTTP — 200 tout va bien, 503 le service est hors d'usage ;
 * l'exploitant qui l'ouvre au navigateur veut, lui, savoir *lequel* des contrôles
 * est tombé, d'où le détail par contrôle.
 *
 * Sans hôte : l'endpoint répond sur les trois zones **et** sur l'adresse interne
 * du conteneur, que la sonde Docker est seule à connaître. Ouvert sans
 * authentification (la sonde n'a pas de session) et donc sans rien livrer
 * d'exploitable : pas de version, pas de DSN, pas de trace — un « ok » ou un
 * « ko » par contrôle, rien d'autre.
 */
final class SanteController
{
    #[Route('/sante', name: 'sante', methods: ['GET'])]
    public function __invoke(VerificateurSante $verificateur): JsonResponse
    {
        $bilan = $verificateur->verifier();
        $enBonneSante = $bilan->estEnBonneSante();

        $reponse = new JsonResponse(
            [
                'statut' => $enBonneSante ? 'ok' : 'hors-service',
                'controles' => array_map(
                    static fn (bool $verdict): string => $verdict ? 'ok' : 'ko',
                    $bilan->resultats(),
                ),
            ],
            $enBonneSante ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );

        // Un état de santé servi depuis un cache (navigateur, proxy) est un
        // mensonge en puissance : il faut qu'il soit recalculé à chaque passage.
        $reponse->headers->set('Cache-Control', 'no-store');

        return $reponse;
    }
}
