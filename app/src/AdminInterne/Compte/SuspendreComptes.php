<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte;

use App\Liste\ActionGroupee\ActionGroupee;
use App\Liste\ActionGroupee\AsActionGroupee;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Action de groupe (#47) : suspend les comptes sélectionnés. Chaque suspension est
 * tracée au journal d'audit par le service.
 */
#[AsActionGroupee('comptes.suspendre')]
final readonly class SuspendreComptes implements ActionGroupee
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
            if ($this->administration->suspendre((int) $identifiant, $auteur)) {
                ++$nombre;
            }
        }

        return \sprintf('%d compte(s) suspendu(s).', $nombre);
    }
}
