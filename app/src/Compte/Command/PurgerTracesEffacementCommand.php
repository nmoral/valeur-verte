<?php

declare(strict_types=1);

namespace App\Compte\Command;

use App\Compte\PurgeTracesEffacement;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Point d'entrée cron de la purge des traces d'effacement (#332) : supprime les
 * empreintes dont la rétention (12 mois) est échue. Idempotente — à jouer une
 * fois par jour par un cron (sur le VPS, §5 ; registre #190). Sans effet s'il n'y
 * a rien à purger.
 */
#[AsCommand(
    name: 'app:compte:purger-traces-effacement',
    description: 'Purge les traces d\'effacement dont la rétention (12 mois) est échue.',
)]
final class PurgerTracesEffacementCommand extends Command
{
    public function __construct(private readonly PurgeTracesEffacement $purge)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $supprimees = $this->purge->executer();

        (new SymfonyStyle($input, $output))->success(\sprintf(
            '%d trace(s) d\'effacement purgée(s).',
            $supprimees,
        ));

        return Command::SUCCESS;
    }
}
