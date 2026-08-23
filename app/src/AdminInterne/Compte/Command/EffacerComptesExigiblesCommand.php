<?php

declare(strict_types=1);

namespace App\AdminInterne\Compte\Command;

use App\AdminInterne\Compte\EffacementCompte;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Finaliseur de grâce du droit à l'effacement (#308, RGPD) : efface
 * définitivement les comptes dont la demande de suppression a dépassé le délai de
 * grâce. Idempotente — à jouer une fois par jour par un cron (sur le VPS, §5 ;
 * registre #190). Sans effet s'il n'y a rien à effacer. L'auteur de l'acte tracé
 * au journal d'audit est le système : c'est l'échéance, non une main, qui déclenche.
 */
#[AsCommand(
    name: 'app:compte:effacer-exigibles',
    description: 'Efface les comptes dont la demande de suppression a dépassé le délai de grâce.',
)]
final class EffacerComptesExigiblesCommand extends Command
{
    public function __construct(private readonly EffacementCompte $effacement)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bilan = $this->effacement->effacerLesComptesExigibles('systeme');

        (new SymfonyStyle($input, $output))->success(\sprintf(
            '%d compte(s) effacé(s) définitivement.',
            $bilan->comptesEffaces(),
        ));

        return Command::SUCCESS;
    }
}
