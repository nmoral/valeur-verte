<?php

declare(strict_types=1);

namespace App\Liste\ActionGroupee;

/**
 * Action exécutable sur une sélection de lignes d'une liste (déblocage, marquage,
 * suppression…). Déclarée via #[AsActionGroupee] et résolue par clé, comme les
 * sources. Renvoie un message de résultat destiné au retour utilisateur.
 */
interface ActionGroupee
{
    /**
     * @param list<string> $identifiants identifiants des lignes sélectionnées
     */
    public function executer(array $identifiants): string;
}
