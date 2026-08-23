<?php

declare(strict_types=1);

namespace App\Security\ChangementEmail;

/**
 * Confirmation de changement d'adresse refusée (#310) : jeton inconnu, expiré,
 * déjà confirmé — ou adresse devenue indisponible entre la demande et le clic.
 * Ne distingue jamais le motif : la route de confirmation est publique, et
 * détailler y ferait un oracle sur l'existence des comptes et des demandes.
 */
final class ChangementEmailInvalide extends \RuntimeException
{
}
