<?php

declare(strict_types=1);

namespace App\Security\DeuxFacteurs\Command;

use App\Security\DeuxFacteurs\EnrolementSecondFacteur;
use App\Security\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ouvre l'enrôlement du second facteur d'un admin (#312). À jouer **sur le VPS**,
 * en SSH.
 *
 * C'est la porte d'entrée unique : sans ce lien, personne n'enrôle de téléphone,
 * fût-ce avec le bon mot de passe. C'est ce qui empêche qu'un mot de passe déjà
 * volé serve à enrôler l'appareil du voleur le jour de la mise en production —
 * auquel cas le second facteur aurait évincé l'admin légitime au lieu de le
 * protéger.
 *
 * La même commande sert trois fois : premier enrôlement, changement de téléphone,
 * et déblocage d'un admin qui a perdu son téléphone **et** ses codes de secours.
 */
#[AsCommand(
    name: 'app:admin:second-facteur:enroler',
    description: 'Ouvre l\'enrôlement du second facteur d\'un compte admin et rend le lien à usage unique.',
)]
final class EnrolerSecondFacteurCommand extends Command
{
    public function __construct(
        private readonly UserRepository $utilisateurs,
        private readonly EnrolementSecondFacteur $enrolement,
        private readonly string $appDomaine,
        private readonly int $dureeValiditeMinutes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Adresse email du compte admin à enrôler');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $user = $this->utilisateurs->findOneBy(['email' => $email]);
        if (null === $user) {
            $style->error(\sprintf('Aucun compte avec l\'adresse « %s ».', $email));

            return Command::FAILURE;
        }

        // L'exigence ne porte que sur l'admin interne : enrôler un pro lui
        // laisserait croire qu'il est protégé alors que rien ne lui demandera
        // jamais son code.
        if (!\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $style->error(\sprintf('Le compte « %s » n\'est pas un compte d\'administration.', $email));

            return Command::FAILURE;
        }

        $jeton = $this->enrolement->ouvrirPour($user);

        $style->success('Lien d\'enrôlement ouvert.');
        $style->writeln(\sprintf(
            'https://admin.%s/second-facteur/enrolement?jeton=%s',
            $this->appDomaine,
            $jeton,
        ));
        $style->writeln('');
        $style->note(\sprintf(
            'À usage unique, valable %d minutes. Il ouvre le QR à scanner ; le second facteur '
            .'ne devient actif qu\'une fois un code confirmé, et les codes de secours ne '
            .'s\'affichent qu\'à ce moment-là.',
            $this->dureeValiditeMinutes,
        ));

        return Command::SUCCESS;
    }
}
