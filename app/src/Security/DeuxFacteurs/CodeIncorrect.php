<?php

declare(strict_types=1);

namespace App\Security\DeuxFacteurs;

/**
 * Code à six chiffres refusé lors de la confirmation d'enrôlement (#312).
 * Distinct de {@see EnrolementInvalide} : le lien, lui, reste valable — une faute
 * de frappe ne doit pas obliger à retourner en SSH pour en émettre un autre.
 */
final class CodeIncorrect extends \RuntimeException
{
}
