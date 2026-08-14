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

    public static function parNom(string $nom): self
    {
        foreach (self::cases() as $poste) {
            if ($poste->name === $nom) {
                return $poste;
            }
        }

        throw new \InvalidArgumentException(\sprintf('Poste inconnu : %s.', $nom));
    }

    public function unite(): Unite
    {
        return match ($this) {
            self::Murs, self::Combles, self::PlanchersBas => Unite::EuroParM2,
            self::Menuiseries => Unite::EuroParUnite,
            self::Chauffage, self::Ecs, self::Vmc => Unite::EuroForfait,
        };
    }

    public function libelle(): string
    {
        return match ($this) {
            self::Murs => 'Isolation des murs',
            self::Combles => 'Isolation des combles',
            self::PlanchersBas => 'Planchers bas',
            self::Menuiseries => 'Menuiseries',
            self::Chauffage => 'Pompe à chaleur air/eau',
            self::Ecs => 'Eau chaude sanitaire',
            self::Vmc => 'VMC double flux',
        };
    }
}
