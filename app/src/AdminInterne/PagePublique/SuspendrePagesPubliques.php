<?php

declare(strict_types=1);

namespace App\AdminInterne\PagePublique;

use App\Liste\ActionGroupee\ActionGroupee;
use App\Liste\ActionGroupee\AsActionGroupee;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Action de groupe (#268) : coupe les pages publiques sélectionnées. Chaque coupure
 * est tracée au journal d'audit par le service.
 */
#[AsActionGroupee('pages_publiques.suspendre')]
final readonly class SuspendrePagesPubliques implements ActionGroupee
{
    public function __construct(
        private AdministrationPagesPubliques $administration,
        private Security $security,
    ) {
    }

    public function executer(array $identifiants): string
    {
        $auteur = $this->security->getUser()?->getUserIdentifier() ?? 'inconnu';
        $nombre = 0;
        foreach ($identifiants as $identifiant) {
            if ($this->administration->suspendre($identifiant, $auteur)) {
                ++$nombre;
            }
        }

        return \sprintf('%d page(s) suspendue(s).', $nombre);
    }
}
