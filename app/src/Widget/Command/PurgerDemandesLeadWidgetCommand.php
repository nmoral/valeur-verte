<?php

declare(strict_types=1);

namespace App\Widget\Command;

use App\Widget\PurgeDemandesLeadWidget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rétention des demandes de lead du widget (#292) : supprime celles qui n'ont
 * jamais été confirmées passé le délai de grâce, et celles déjà matérialisées en
 * Rapport passé le délai de garde. Idempotente — à jouer une fois par jour par un
 * cron (sur le VPS, §5 ; registre #190). Sans effet s'il n'y a rien à purger.
 */
#[AsCommand(
    name: 'app:widget:purger-demandes-lead',
    description: 'Supprime les demandes de lead du widget au-delà des délais de rétention.',
)]
final class PurgerDemandesLeadWidgetCommand extends Command
{
    public function __construct(private readonly PurgeDemandesLeadWidget $purge)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $supprimees = $this->purge->executer();

        (new SymfonyStyle($input, $output))->success(\sprintf('%d demande(s) de lead purgée(s).', $supprimees));

        return Command::SUCCESS;
    }
}
