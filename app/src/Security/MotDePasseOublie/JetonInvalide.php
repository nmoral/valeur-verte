<?php

declare(strict_types=1);

namespace App\Security\MotDePasseOublie;

/**
 * Jeton de réinitialisation refusé (#194) : inconnu, expiré ou déjà consommé. Ne
 * distingue jamais le motif — aucun oracle sur l'existence d'un compte ou d'un jeton.
 */
final class JetonInvalide extends \RuntimeException
{
}
