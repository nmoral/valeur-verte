<?php

declare(strict_types=1);

namespace App\Security;

use App\BackOffice\Entreprise\EnregistreurFicheEntreprise;
use App\BackOffice\Entreprise\Siret;
use App\Compte\Compte;
use App\Compte\CompteRepository;
use App\Compte\EmpreinteEffacement;
use App\Compte\TraceEffacementRepository;
use App\Tenant\ProvisionneurCompte;
use App\Tenant\TenantRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Création d'un compte pro en self-service (ONB-1) : compte actif immédiatement,
 * sans validation manuelle. Provisionne le tenant du pro (D3, #54) et, si un
 * SIRET est fourni, rattache la fiche entreprise pré-remplie (ONB-2, #106).
 */
final class CreateurDeCompte
{
    public function __construct(
        private readonly UserRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TenantRepository $tenants,
        private readonly EnregistreurFicheEntreprise $fichesEntreprise,
        private readonly ProvisionneurCompte $provisionneur,
        private readonly CompteRepository $comptes,
        private readonly ClockInterface $horloge,
        private readonly TraceEffacementRepository $traces,
        private readonly EmpreinteEffacement $empreintes,
    ) {
    }

    /**
     * @throws EmailDejaUtilise
     */
    public function creer(string $email, string $motDePasse, string $versionConditions, ?Siret $siret = null): User
    {
        // Unicité sur l'**identité**, pas sur la chaîne saisie (#331) : sans quoi
        // un seul pro s'offre autant d'essais gratuits qu'il a d'alias.
        if (null !== $this->utilisateurs->parAdresse($email)) {
            throw new EmailDejaUtilise($email);
        }

        $tenant = $this->tenants->nouveau();
        $tenantId = $tenant->id();
        \assert(null !== $tenantId);

        // Agrégat compte (#61) : porteur de la formule et des droits d'essai,
        // distinct de l'utilisateur. Classique + candidature fondateur en attente.
        $compte = Compte::creer($tenantId, $tenant->creeLe());

        // Compte créé **sans nouvel essai** dans deux cas où on vise l'abus, pas la
        // personne (l'inscription n'est jamais refusée) :
        //  - réinscription d'une adresse déjà effacée (#332), sinon supprimer puis
        //    recréer serait le contournement le plus propre ;
        //  - domaine de messagerie jetable (#343), filet complémentaire du mur de
        //    vérification du 2ᵉ rapport.
        $adresse = AdresseEmail::depuis($email);
        $empreinte = $this->empreintes->pour($adresse->canonique());
        if (null !== $this->traces->parEmpreinte($empreinte) || $adresse->estJetable()) {
            $compte->neutraliserEssai($this->horloge->now());
        }

        $this->comptes->enregistrer($compte);

        $user = new User($email, ['ROLE_PRO']);
        $user->rattacherAuTenant($tenantId);
        $user->definirMotDePasse($this->hasher->hashPassword($user, $motDePasse));
        // Preuve d'acceptation des conditions (#228) : version en vigueur figée à
        // l'instant de la création, jamais reprise d'une valeur cliente.
        $user->accepterLesConditions($versionConditions, $this->horloge->now());
        $this->utilisateurs->enregistrer($user);

        // Le compte repart d'une configuration par défaut exploitable (ONB-3) :
        // le pro n'a rien à faire avant son premier rapport.
        $this->provisionneur->provisionner($tenantId);

        if (null !== $siret) {
            $this->fichesEntreprise->rattacher($tenantId, $siret);
        }

        return $user;
    }
}
