<?php

declare(strict_types=1);

namespace App\Security\MonCompte;

/**
 * Le mot de passe actuel fourni ne correspond pas au compte (#206) : on refuse le
 * changement plutôt que de modifier à l'aveugle une session ouverte.
 */
final class MotDePasseActuelInvalide extends \RuntimeException
{
}
