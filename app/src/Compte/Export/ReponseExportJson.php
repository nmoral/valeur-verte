<?php

declare(strict_types=1);

namespace App\Compte\Export;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Construit la réponse de téléchargement d'un export (#309) : un JSON lisible,
 * en pièce jointe, nommé par la date du jour. Partagé par le parcours pro
 * (« Mon compte ») et le parcours admin (demande reçue par email).
 */
final class ReponseExportJson
{
    /**
     * @param array<string, mixed> $document
     */
    public static function pour(array $document, \DateTimeImmutable $genereLe): Response
    {
        $json = json_encode($document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        \assert(false !== $json);

        $nomFichier = 'valeur-verte-export-'.$genereLe->format('Y-m-d').'.json';

        $reponse = new Response($json);
        $reponse->headers->set('Content-Type', 'application/json; charset=utf-8');
        $reponse->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $nomFichier),
        );

        return $reponse;
    }
}
