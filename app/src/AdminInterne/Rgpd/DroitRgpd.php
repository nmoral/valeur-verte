<?php

declare(strict_types=1);

namespace App\AdminInterne\Rgpd;

/**
 * Les droits RGPD qu'une demande peut invoquer (#309), tels qu'annoncés sur
 * `/confidentialite`. L'**effacement** n'y figure pas : il relève de la
 * suppression de compte (#308), avec son propre outillage et son délai de grâce.
 */
enum DroitRgpd: string
{
    case Acces = 'acces';
    case Rectification = 'rectification';
    case Portabilite = 'portabilite';
    case Limitation = 'limitation';
    case Opposition = 'opposition';

    public function libelle(): string
    {
        return match ($this) {
            self::Acces => 'Accès',
            self::Rectification => 'Rectification',
            self::Portabilite => 'Portabilité',
            self::Limitation => 'Limitation du traitement',
            self::Opposition => 'Opposition',
        };
    }
}
