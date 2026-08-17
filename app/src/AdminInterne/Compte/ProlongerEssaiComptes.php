<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

use App\Liste\ActionGroupee\ActionGroupee;
use App\Liste\ActionGroupee\AsActionGroupee;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Action de groupe (#47) : prolonge l'essai des comptes sélectionnés d'un palier
 * fixe (14 jours). Chaque prolongation est tracée au journal d'audit.
 */
#[AsActionGroupee('comptes.prolonger')]
final readonly class ProlongerEssaiComptes implements ActionGroupee
{
    private const JOURS = 14;

    public function __construct(
        private AdministrationComptes $administration,
        private Security $security,
    ) {
    }

    public function executer(array $identifiants): string
    {
        $auteur = $this->security->getUser()?->getUserIdentifier() ?? 'inconnu';
        $nombre = 0;
        foreach ($identifiants as $identifiant) {
            if ($this->administration->prolongerEssai((int) $identifiant, self::JOURS, $auteur)) {
                ++$nombre;
            }
        }

        return \sprintf('%d essai(s) prolongé(s) de %d jours.', $nombre, self::JOURS);
    }
}
