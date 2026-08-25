<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence\Command;

use App\AdminInterne\SeoPresence\CollecteurPresenceExterne;
use App\AdminInterne\SeoPresence\RapportPresence;
use App\AdminInterne\SeoPresence\RapportPresenceRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Collecte la présence SEO externe (Search Console + PageSpeed) et archive un
 * snapshot horodaté (#346), planifiable (registre cron #190). **Sans effet de bord
 * externe** : elle ne fait que lire des API officielles et écrire un rapport.
 *
 * Tant que les secrets ne sont pas provisionnés au VPS, la collecte est **non
 * configurée** : la commande le signale et **n'archive rien** (aucun snapshot vide
 * qui fausserait la tendance), en sortant en succès — elle est planifiée d'avance,
 * elle ne doit pas échouer chaque semaine avant le lancement.
 */
#[AsCommand(
    name: 'app:seo:presence:fetch',
    description: 'Collecte la présence SEO externe (Search Console + PageSpeed) et archive un rapport horodaté.',
)]
final class CollecterPresenceSeoCommand extends Command
{
    public function __construct(
        private readonly CollecteurPresenceExterne $collecteur,
        private readonly RapportPresenceRepository $rapports,
        private readonly ClockInterface $horloge,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $presence = $this->collecteur->collecter();
        if (!$presence->configure) {
            $style->warning('Présence externe non configurée (Search Console / PageSpeed absents) : aucun rapport archivé.');

            return Command::SUCCESS;
        }

        $rapport = RapportPresence::depuis($presence, $this->horloge->now());
        $this->rapports->enregistrer($rapport);

        $style->success(\sprintf(
            'Présence externe collectée : %d clic(s), %d impression(s), position moyenne %s, sur %d URL(s).',
            $rapport->clics(),
            $rapport->impressions(),
            null !== $rapport->positionMoyenne() ? number_format($rapport->positionMoyenne(), 1, ',', ' ') : 'n/d',
            $rapport->nombreUrls(),
        ));

        return Command::SUCCESS;
    }
}
