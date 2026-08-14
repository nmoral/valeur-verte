<?php

declare(strict_types=1);

namespace App\Compte\Command;

use App\Compte\RelanceEssais;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Relance de fin d'essai (#108) : envoie l'email de rappel aux comptes dont
 * l'essai approche (J-X). Idempotente — à jouer une fois par jour par un cron
 * (sur le VPS, §5). Sans effet si aucun compte n'est à relancer.
 */
#[AsCommand(
    name: 'app:essai:relancer',
    description: 'Envoie les emails de relance aux comptes dont l\'essai se termine bientôt.',
)]
final class RelancerEssaisCommand extends Command
{
    public function __construct(private readonly RelanceEssais $relance)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $envoyes = $this->relance->executer();

        (new SymfonyStyle($input, $output))->success(\sprintf('%d relance(s) d\'essai envoyée(s).', $envoyes));

        return Command::SUCCESS;
    }
}
