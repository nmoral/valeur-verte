<?php

declare(strict_types=1);

namespace App\AdminInterne\Millesime;

use App\Liste\ActionGroupee\ActionGroupee;
use App\Liste\ActionGroupee\AsActionGroupee;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Action de groupe (#239) : débloque d'un coup les mailles écartées par REG-1
 * (#130) sélectionnées, une fois le millésime publié.
 *
 * Le socle ne transmet que la sélection ; l'identifiant porte donc le millésime
 * (« millesime:codeInsee »), sans ambiguïté puisque la liste est bornée à un
 * millésime. Chaque déblocage passe par {@see DecisionMillesime::debloquer()} :
 * même garde SQL (REG-2) et même trace au journal d'audit que le déblocage par
 * ligne. Une maille refusée par la garde n'interrompt pas les autres — l'action
 * compte les succès et rend compte des échecs.
 */
#[AsActionGroupee('millesimes.publier_mailles')]
final readonly class PublierMaillesGroupees implements ActionGroupee
{
    public function __construct(
        private DecisionMillesime $decision,
        private Security $security,
    ) {
    }

    public function executer(array $identifiants): string
    {
        $auteur = $this->security->getUser()?->getUserIdentifier() ?? 'inconnu';
        $debloquees = 0;
        $echecs = 0;

        foreach ($identifiants as $identifiant) {
            [$millesime, $codeInsee] = self::decoder($identifiant);
            try {
                $this->decision->debloquer($codeInsee, $millesime, $auteur);
                ++$debloquees;
            } catch (\RuntimeException) {
                ++$echecs;
            }
        }

        if ($echecs > 0) {
            return \sprintf('%d maille(s) débloquée(s), %d en échec.', $debloquees, $echecs);
        }

        return \sprintf('%d maille(s) débloquée(s).', $debloquees);
    }

    /**
     * @return array{int, string}
     */
    private static function decoder(string $identifiant): array
    {
        [$millesime, $codeInsee] = array_pad(explode(':', $identifiant, 2), 2, '');

        return [(int) $millesime, $codeInsee];
    }
}
