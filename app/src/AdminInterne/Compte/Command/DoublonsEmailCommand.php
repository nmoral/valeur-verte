<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte\Command;

use App\Security\DoublonsIdentite;
use App\Security\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Contrôle **préalable au déploiement** de l'unicité réelle des adresses (#331).
 * La migration qui pose l'index unique échoue si l'existant porte déjà deux
 * écritures d'une même adresse — et un échec de migration ne dit pas lesquelles.
 * Cette commande les nomme, avant.
 *
 * Strictement en **lecture** : elle ne fusionne ni ne supprime rien. Que faire
 * d'une collision — effacer le compte de trop (#308), le basculer sur une autre
 * adresse (#310), contacter son titulaire — est un arbitrage humain.
 */
#[AsCommand(
    name: 'app:comptes:doublons-email',
    description: 'Liste les comptes qui partagent une même identité d\'adresse email (lecture seule).',
)]
final class DoublonsEmailCommand extends Command
{
    public function __construct(private readonly UserRepository $utilisateurs)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        $groupes = DoublonsIdentite::detecter($this->utilisateurs->adressesParId());

        if ([] === $groupes) {
            $style->success('Aucune collision : l\'unicité peut être posée.');

            return Command::SUCCESS;
        }

        foreach ($groupes as $identite => $comptes) {
            $style->section($identite);
            $style->listing(array_map(
                static fn (int $id, string $adresse): string => \sprintf('#%d — %s', $id, $adresse),
                array_keys($comptes),
                array_values($comptes),
            ));
        }

        // Sortie en échec : jouée dans un enchaînement de déploiement, la commande
        // doit l'arrêter — la migration échouerait de toute façon, plus loin et
        // moins clairement.
        $style->error(\sprintf('%d identité(s) partagée(s) par plusieurs comptes.', \count($groupes)));

        return Command::FAILURE;
    }
}
