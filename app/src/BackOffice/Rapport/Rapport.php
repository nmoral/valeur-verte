<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport;

use App\BackOffice\Restitution\AjustementProfessionnel;
use App\BackOffice\Restitution\DeclarationsPro;
use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rapport de valeur verte. Un rapport porteur d'un contact est un lead : les
 * deux vivent dans une seule liste (§9.2).
 *
 * Le contact (value object optionnel) est mappé en trois colonnes nullables et
 * reconstruit à la lecture : Doctrine ne sait pas rendre un embeddable nullable.
 */
#[ORM\Entity(repositoryClass: RapportRepository::class)]
#[ORM\Table(name: 'rapport')]
class Rapport implements TenantAwareInterface
{
    use TenantAwareTrait;
    /**
     * Ce qui tient la place d'une adresse qu'on ne sait pas borner (libellé sans
     * code postal) : l'instantané doit rester lisible — `SaisieAudit` refuse une
     * adresse vide et le PDF s'en régénère (D10, #68).
     */
    public const string ADRESSE_ABSENTE = '-';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $reference;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(enumType: Source::class)]
    private Source $source;

    #[ORM\Column(length: 255)]
    private string $adresse;

    #[ORM\Column(enumType: Dpe::class, length: 1)]
    private Dpe $dpeAvant;

    #[ORM\Column(enumType: Dpe::class, length: 1)]
    private Dpe $dpeApres;

    #[ORM\Column]
    private int $valeurVerteEstimee;

    #[ORM\Column(length: 255)]
    private string $pdfUrl;

    /**
     * Instantané immuable du contexte de calcul (#33, §9.1, D5), sérialisé en
     * JSONB : le PDF est régénéré à partir de lui, jamais recalculé. Écrit une
     * fois à la création — un UPDATE serait un bug (§9.1).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $instantane;

    /**
     * Ligne d'ajustement du professionnel (#30, §4), dans un champ **distinct**
     * de l'instantané figé : l'ajustement est l'annotation propre au pro, jamais
     * une surcharge de la mesure. Mutable (le pro pose sa ligne après coup), là où
     * l'instantané statistique, lui, reste immuable (#33).
     *
     * @var array{montant_euros: int, motif: string}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $ajustement = null;

    /**
     * Déclarations du professionnel (#26) : situation de revenus du ménage et
     * retouches de chiffrage. Même statut que l'ajustement ci-dessus — annotations
     * du pro, mutables, distinctes de l'instantané figé dont elles ne touchent rien.
     * Le reste à charge se recalcule à l'affichage à partir des deux.
     *
     * @var array{categorie_revenu: string, chiffrage: array<string, int>}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $declarations = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactNom;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactEmail;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $contactTelephone;

    /**
     * Date d'anonymisation (D10, #68) : marque qu'un rapport a déjà perdu son
     * contact et vu son adresse bornée. Sert de garde d'idempotence — la purge
     * quotidienne ne repasse jamais sur un rapport déjà traité — et de trace
     * pour le registre des traitements.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $anonymiseLe = null;

    private function __construct(
        string $reference,
        \DateTimeImmutable $creeLe,
        Source $source,
        InstantaneRapport $instantane,
        string $pdfUrl,
        ?Contact $contact,
    ) {
        $saisie = $instantane->saisie();

        $this->reference = $reference;
        $this->creeLe = $creeLe;
        $this->source = $source;
        $this->instantane = $instantane->toArray();
        // Caractéristiques de listing (#37) dérivées de l'instantané : une seule
        // source de vérité, jamais désynchronisée du contexte figé.
        $this->adresse = $saisie->adresse();
        $this->dpeAvant = $saisie->dpeAvant();
        $this->dpeApres = $saisie->dpeApres();
        $this->valeurVerteEstimee = $instantane->restitution()?->montantEuros() ?? 0;
        $this->pdfUrl = $pdfUrl;
        $this->contactNom = $contact?->nom();
        $this->contactEmail = $contact?->email();
        $this->contactTelephone = $contact?->telephone();
    }

    public static function creer(
        string $reference,
        \DateTimeImmutable $creeLe,
        Source $source,
        InstantaneRapport $instantane,
        string $pdfUrl,
        ?Contact $contact = null,
    ): self {
        $reference = trim($reference);
        if ('' === $reference) {
            throw new \InvalidArgumentException('La référence du rapport ne peut pas être vide.');
        }

        return new self($reference, $creeLe, $source, $instantane, $pdfUrl, $contact);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function creeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function source(): Source
    {
        return $this->source;
    }

    public function adresse(): string
    {
        return $this->adresse;
    }

    public function dpeAvant(): Dpe
    {
        return $this->dpeAvant;
    }

    public function dpeApres(): Dpe
    {
        return $this->dpeApres;
    }

    public function valeurVerteEstimee(): int
    {
        return $this->valeurVerteEstimee;
    }

    public function pdfUrl(): string
    {
        return $this->pdfUrl;
    }

    public function instantane(): InstantaneRapport
    {
        return InstantaneRapport::fromArray($this->instantane);
    }

    public function contact(): ?Contact
    {
        if (null === $this->contactNom) {
            return null;
        }

        return Contact::creer($this->contactNom, $this->contactEmail, $this->contactTelephone);
    }

    public function estUnLead(): bool
    {
        return null !== $this->contactNom;
    }

    public function anonymiseLe(): ?\DateTimeImmutable
    {
        return $this->anonymiseLe;
    }

    public function estAnonymise(): bool
    {
        return null !== $this->anonymiseLe;
    }

    /**
     * Anonymise le rapport à l'échéance de rétention (D10, #68) : le contact
     * disparaît, l'adresse tombe à sa commune — dans la colonne **et** dans
     * l'instantané figé, faute de quoi le PDF, régénéré depuis le JSONB, la
     * ressusciterait. Les calculs ne sont pas touchés : mêmes chiffres, même
     * contexte, même référence (`CLAUDE.md` §7).
     */
    public function anonymiser(\DateTimeImmutable $quand): void
    {
        $this->contactNom = null;
        $this->contactEmail = null;
        $this->contactTelephone = null;

        // Une adresse vide rendrait l'instantané illisible (`SaisieAudit` l'interdit)
        // et casserait la régénération du PDF : sans commune à garder, un tiret
        // tient la place.
        $borne = AdresseCommune::borner($this->adresse);
        $this->adresse = '' === $borne ? self::ADRESSE_ABSENTE : $borne;

        $saisie = $this->instantane['saisie'] ?? null;
        \assert(\is_array($saisie), 'Tout instantané porte une saisie.');
        $saisie['adresse'] = $this->adresse;
        $this->instantane['saisie'] = $saisie;

        $this->anonymiseLe = $quand;
    }

    /**
     * Pose (ou remplace) la ligne d'ajustement du pro (#30). N'écrit que le champ
     * dédié : la mesure statistique figée dans l'instantané reste intacte.
     */
    public function ajusterPar(AjustementProfessionnel $ajustement): void
    {
        $this->ajustement = $ajustement->toArray();
    }

    public function ajustement(): ?AjustementProfessionnel
    {
        if (null === $this->ajustement) {
            return null;
        }

        return AjustementProfessionnel::fromArray($this->ajustement);
    }

    /**
     * Pose (ou remplace) les déclarations du pro (#26). Le pro affine sa saisie :
     * c'est une correction, pas un empilement. L'instantané reste intact.
     */
    public function declarerPar(DeclarationsPro $declarations): void
    {
        $this->declarations = $declarations->toArray();
    }

    /**
     * Toujours un objet exploitable, jamais null : le reste à charge se calcule
     * sans branche de garde, même sur un rapport que le pro n'a pas annoté.
     */
    public function declarations(): DeclarationsPro
    {
        if (null === $this->declarations) {
            return DeclarationsPro::aucune();
        }

        return DeclarationsPro::fromArray($this->declarations);
    }
}
