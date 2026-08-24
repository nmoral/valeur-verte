<?php

declare(strict_types=1);

namespace App\Security\DeuxFacteurs;

/**
 * Lien d'enrôlement refusé (#312) : inconnu, expiré ou déjà consommé. Ne
 * distingue jamais le motif — l'écran d'enrôlement est atteignable sans session
 * particulière, détailler y ferait un oracle.
 */
final class EnrolementInvalide extends \RuntimeException
{
}
