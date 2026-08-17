<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Liste\ActionGroupee\RegistreActionsGroupees;
use App\Liste\Direction;
use App\Liste\PageListe;
use App\Liste\ParametresListe;
use App\Liste\RegistreSources;
use App\Liste\SourceListe;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Socle d'un LiveComponent de liste : porte l'état d'affichage (page, tri, sens,
 * recherche, sélection) et les actions (trier, paginer, exécuter une action de
 * groupe). Chaque liste concrète déclare sa clé de source, ses colonnes, ses
 * actions autorisées et le rendu de ses lignes. La donnée est toujours interrogée
 * côté serveur via la source (tenant/lecture seule respectés en amont).
 */
trait ComporteListe
{
    /** Tailles de page proposées à l'utilisateur (liste blanche). */
    private const TAILLES_PAGE = [10, 25, 50, 100];

    #[LiveProp(writable: true)]
    public int $page = 1;

    #[LiveProp(writable: true)]
    public string $tri = '';

    #[LiveProp(writable: true)]
    public string $direction = 'asc';

    #[LiveProp(writable: true)]
    public string $recherche = '';

    /**
     * @var list<string>
     */
    #[LiveProp(writable: true)]
    public array $selection = [];

    /**
     * Taille de page choisie par l'utilisateur ; ``null`` = le défaut de la liste.
     * En changer remet en première page (la page courante n'a plus de sens).
     */
    #[LiveProp(writable: true, onUpdated: 'reinitialiserPagination')]
    public ?int $parPageChoisi = null;

    #[LiveProp]
    public ?string $message = null;

    private RegistreSources $registreSources;

    private RegistreActionsGroupees $registreActions;

    #[Required]
    public function brancherRegistresListe(RegistreSources $sources, RegistreActionsGroupees $actions): void
    {
        $this->registreSources = $sources;
        $this->registreActions = $actions;
    }

    /**
     * @return PageListe<mixed>
     */
    public function resultat(): PageListe
    {
        $source = $this->source();
        $parametres = ParametresListe::depuis(
            $this->page,
            $this->tailleActuelle(),
            $this->tri,
            $this->direction,
            $this->recherche,
            $source->colonnes(),
        );

        return $source->interroger($parametres);
    }

    #[LiveAction]
    public function trier(#[LiveArg] string $colonne): void
    {
        if ($this->tri === $colonne) {
            $this->direction = Direction::depuis($this->direction)->inverse()->value;
        } else {
            $this->tri = $colonne;
            $this->direction = Direction::Asc->value;
        }

        $this->page = 1;
    }

    #[LiveAction]
    public function allerPage(#[LiveArg] int $page): void
    {
        $this->page = max(1, $page);
    }

    #[LiveAction]
    public function executerGroupe(#[LiveArg] string $cle): void
    {
        $autorisees = array_column($this->actionsGroupees(), 'cle');
        if (!\in_array($cle, $autorisees, true)) {
            throw new \InvalidArgumentException(\sprintf('Action de groupe non autorisée : « %s ».', $cle));
        }

        $this->message = $this->registreActions->action($cle)->executer($this->selection);
        $this->selection = [];
        $this->page = 1;
    }

    /**
     * Colonnes affichées, avec leur libellé et si elles sont triables.
     *
     * @return list<array{cle: string, libelle: string, triable: bool}>
     */
    public function colonnes(): array
    {
        return [];
    }

    /**
     * Actions de groupe autorisées pour cette liste (liste blanche).
     *
     * @return list<array{cle: string, libelle: string, confirmation?: string}>
     */
    public function actionsGroupees(): array
    {
        return [];
    }

    public function identifiant(mixed $element): string
    {
        return '';
    }

    public function classeLigne(mixed $element): string
    {
        return '';
    }

    protected function parPage(): int
    {
        return 25;
    }

    /**
     * Taille de page effective : le choix de l'utilisateur s'il est valide, sinon
     * le défaut de la liste (surchargeable par ``parPage()``).
     */
    public function tailleActuelle(): int
    {
        if (null !== $this->parPageChoisi && \in_array($this->parPageChoisi, self::TAILLES_PAGE, true)) {
            return $this->parPageChoisi;
        }

        return $this->parPage();
    }

    /**
     * @return list<int>
     */
    public function taillesDisponibles(): array
    {
        return self::TAILLES_PAGE;
    }

    public function reinitialiserPagination(): void
    {
        $this->page = 1;
    }

    /**
     * Amorce la taille sur le défaut de la liste, pour que le sélecteur (lié en
     * ``data-model``) affiche la bonne valeur dès le premier rendu — sans quoi un
     * ``null`` retomberait sur la première option.
     */
    #[PostMount]
    public function initialiserTaillePage(): void
    {
        $this->parPageChoisi ??= $this->parPage();
    }

    /**
     * Source interrogée. Par défaut résolue par clé via le registre ; une liste
     * paramétrée (ex. par un millésime) peut surcharger pour la construire elle-même.
     */
    protected function source(): SourceListe
    {
        return $this->registreSources->source($this->cleSource());
    }

    abstract protected function cleSource(): string;
}
