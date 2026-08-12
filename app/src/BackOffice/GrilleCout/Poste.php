<?php

declare(strict_types=1);

namespace App\BackOffice\GrilleCout;

enum Poste
{
    case Murs;
    case Combles;
    case PlanchersBas;
    case Menuiseries;
    case Chauffage;
    case Ecs;
    case Vmc;

    public function unite(): Unite
    {
        return match ($this) {
            self::Murs, self::Combles, self::PlanchersBas => Unite::EuroParM2,
            self::Menuiseries => Unite::EuroParUnite,
            self::Chauffage, self::Ecs, self::Vmc => Unite::EuroForfait,
        };
    }
}
