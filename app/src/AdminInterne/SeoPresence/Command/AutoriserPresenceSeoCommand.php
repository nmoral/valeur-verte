<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoPresence\Command;

use App\AdminInterne\SeoPresence\Google\AutorisationOAuthGoogle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Amorçage « une fois » du refresh token OAuth Search Console (#346). À jouer avec
 * `GSC_OAUTH_CLIENT_ID`/`GSC_OAUTH_CLIENT_SECRET` déjà renseignés : la commande imprime
 * l'URL de consentement (liée à **ce** client, ce qui évite l'erreur
 * `unauthorized_client`), on autorise dans le navigateur, on recopie le paramètre `code`
 * de l'URL de redirection, et elle rend le `GSC_OAUTH_REFRESH_TOKEN` à coller en secret.
 */
#[AsCommand(
    name: 'app:seo:presence:autoriser',
    description: 'Obtient un refresh token OAuth Search Console (amorçage unique).',
)]
final class AutoriserPresenceSeoCommand extends Command
{
    public function __construct(
        private readonly AutorisationOAuthGoogle $autorisation,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        if (!$this->autorisation->estConfigure()) {
            $style->error('Définis d\'abord GSC_OAUTH_CLIENT_ID et GSC_OAUTH_CLIENT_SECRET (.env.local).');

            return Command::FAILURE;
        }

        $style->section('1) Ouvre cette URL, connecte-toi avec le compte ayant accès à la propriété Search Console, autorise :');
        $style->writeln($this->autorisation->urlConsentement());
        $style->newLine();
        $style->writeln('2) Le navigateur est redirigé vers '.AutorisationOAuthGoogle::REDIRECT.'/?code=... (page qui ne charge pas, c\'est normal).');
        $style->writeln('   Copie la valeur du paramètre « code » depuis la barre d\'adresse.');

        $reponse = $style->ask('3) Colle le code ici');
        $code = urldecode(trim(\is_string($reponse) ? $reponse : ''));
        $refresh = $this->autorisation->echanger($code);

        if (null === $refresh) {
            $style->error('Échec de l\'échange du code. Vérifie que « '.AutorisationOAuthGoogle::REDIRECT.' » est un redirect URI autorisé du client, et recommence.');

            return Command::FAILURE;
        }

        $style->success('Refresh token obtenu. Colle cette ligne dans app/.env.local (secret, non versionné) :');
        $style->writeln('GSC_OAUTH_REFRESH_TOKEN='.$refresh);

        return Command::SUCCESS;
    }
}
