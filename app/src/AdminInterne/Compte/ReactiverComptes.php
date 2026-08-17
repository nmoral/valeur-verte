<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

use App\Liste\ActionGroupee\ActionGroupee;
use App\Liste\ActionGroupee\AsActionGroupee;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Action de groupe (#47) : réactive les comptes sélectionnés qui étaient suspendus.
 */
#[AsActionGroupee('comptes.reactiver')]
final readonly class ReactiverComptes implements ActionGroupee
{
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
            if ($this->administration->reactiver((int) $identifiant, $auteur)) {
                ++$nombre;
            }
        }

        return \sprintf('%d compte(s) réactivé(s).', $nombre);
    }
}
