<?php

declare(strict_types=1);

namespace App\BackOffice\Restitution;

/**
 * Ligne d'ajustement du professionnel invalide (#30, §4) : motif manquant ou
 * montant nul. Validation de frontière — le pro pose une ligne, pas un chiffre
 * vide de sens.
 */
final class AjustementInvalide extends \DomainException
{
}
