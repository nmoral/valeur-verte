"""CLI de calcul des coefficients par département (#91, §10.6).

Le pool du département est chargé une seule fois, puis chaque commune est
calculée par le moteur et **archivée** dans ``coefficient_maille_historique``.
REG-2 (#130) : le batch ne touche plus la table vive ``coefficient_maille`` —
un millésime n'est servi qu'après activation explicite. Une commune sous le
seuil est **refusée** : c'est un résultat normal du cadrage (§7), pas une
erreur ; le refus est noté dans ``maille_refusee`` (il ne retirera la commune du
service qu'à la publication) et le rapport doit les distinguer pour rester
lisible.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from dataclasses import replace
from typing import Any

import pytest

from valeur_verte_data import cli, orchestration
from valeur_verte_data.batch import MailleCoefficient
from valeur_verte_data.config import CalculConfig, resolve_config
from valeur_verte_data.degraded import CoverageMode
from valeur_verte_data.dvf import Mutation
from valeur_verte_data.ecarts import ClasseEcart, EcartMaille
from valeur_verte_data.invariants import ResultatMonotonie, VerdictPublication

_COMMUNES = [
    {
        "code": "31555",
        "nom": "Toulouse",
        "codeDepartement": "31",
        "centre": {"type": "Point", "coordinates": [1.44, 43.6]},
    },
    {
        "code": "31149",
        "nom": "Colomiers",
        "codeDepartement": "31",
        "centre": {"type": "Point", "coordinates": [1.33, 43.61]},
    },
]


def _vente(numero: int, dpe: str, prix: float) -> Mutation:
    return Mutation(
        id_mutation=f"m{numero}",
        date_mutation="2024-01-01",
        millesime=2024,
        code_insee="31555",
        code_postal=None,
        nom_commune=None,
        type_local="Maison",
        nombre_pieces_principales=None,
        prix=prix,
        surface_bati=80.0,
        surface_terrain=200.0,
        prix_m2=prix / 80.0,
        adresse_numero=None,
        adresse_suffixe=None,
        adresse_nom_voie=None,
        longitude=1.44,
        latitude=43.6,
        etiquette_dpe=dpe,
        periode_construction="1948-1974",
    )


def _vente_ariegeoise() -> Mutation:
    """Vente d'un département limitrophe, présente dans le pool mais non publiable."""
    vente = _vente(999, "D", 180000.0)
    return replace(vente, id_mutation="m-09", code_insee="09122")


# Assez de ventes pour dépasser le seuil local et publier chaque classe DPE
# (≥ min_class_sample=20 par classe, avec marge après écrêtage).
_POOL = [_vente(i, "ABCDEFG"[i % 7], 200000 + i * 900) for i in range(210)]

# Le pool synthétique n'a aucun signal DPE réel (R² ≈ 0) : INV-4 (#122) le
# rejetterait. Les tests qui vérifient le *plumbing* de publication (chargement,
# débordement, écriture) neutralisent donc INV-4 par une config permissive.
_CONFIG_PUBLIANTE = replace(CalculConfig(), r2_min=0.0, erreur_mediane_max_pct=100.0)


def _neutraliser_monotonie(monkeypatch: pytest.MonkeyPatch) -> None:
    """Plumbing : le pool synthétique n'a aucun signal DPE, ses coefficients ne
    sont pas monotones et INV-1 les rejetterait. On la neutralise pour isoler le
    câblage de publication (chargement, débordement, écriture)."""
    monkeypatch.setattr(
        orchestration,
        "evaluer_monotonie_dpe",
        lambda maille, config: ResultatMonotonie(raisons=(), inversions_tolerees=()),
    )


def _neutraliser_signe_ordre(monkeypatch: pytest.MonkeyPatch) -> None:
    """Plumbing : les coefficients du pool synthétique (signe/ordre invraisemblables)
    seraient rejetés par INV-2. On la neutralise pour isoler le câblage de publication."""
    monkeypatch.setattr(
        orchestration,
        "evaluer_signe_ordre_grandeur",
        lambda maille, config: VerdictPublication(publiable=True, raisons=()),
    )


class _FauxFetcher:
    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any:
        return _COMMUNES

    def get_text(self, url: str, params: Mapping[str, str] | None = None) -> str:
        raise AssertionError("le calcul ne récupère aucun fichier")


class _FausseConnexion:
    def __init__(self, pool: Sequence[Mutation]) -> None:
        self.pool = list(pool)
        self.requetes: list[str] = []
        self.ecritures: list[tuple[str, Sequence[Any] | None]] = []
        self.commits = 0
        self.rollbacks = 0

    def execute(self, query: str, params: Sequence[Any] | None = None) -> Any:
        self.requetes.append(query)
        if "INSERT" in query.upper():
            self.ecritures.append((query, params))
        return _Curseur([])

    def commit(self) -> None:
        self.commits += 1

    def rollback(self) -> None:
        self.rollbacks += 1


class _Curseur:
    def __init__(self, rows: list[tuple[Any, ...]]) -> None:
        self._rows = rows

    def fetchall(self) -> list[tuple[Any, ...]]:
        return self._rows


@pytest.fixture
def connexion() -> _FausseConnexion:
    return _FausseConnexion(_POOL)


# REG-2 (#130) : archivage et refus sont **tous deux** des INSERT et se retrouvent
# donc dans ``ecritures``. On les distingue par leur table, sans quoi « la maille
# n'est pas archivée » serait confondu avec « aucun refus n'a été noté ».
_TABLE_ARCHIVE = "coefficient_maille_historique"
_TABLE_REFUS = "data.maille_refusee"


def _archivees(connexion: _FausseConnexion) -> list[Sequence[Any]]:
    """Paramètres des écritures visant l'archive versionnée par millésime."""
    return [params for query, params in connexion.ecritures if _TABLE_ARCHIVE in query and params]


def _refus_notes(connexion: _FausseConnexion) -> list[Sequence[Any]]:
    """Paramètres des refus notés (``code_insee``, ``millesime``, ``motif``)."""
    return [params for query, params in connexion.ecritures if _TABLE_REFUS in query and params]


def _capturer_archives(
    monkeypatch: pytest.MonkeyPatch,
) -> list[tuple[MailleCoefficient, ClasseEcart | None]]:
    """Intercepte l'archivage : c'est là que va désormais chaque maille retenue.

    Le verdict REG-1 part avec l'archive (``classe_ecart``), c'est lui qui permet à
    la publication d'écarter les mailles aberrantes — on le capture aussi.
    """
    archivees: list[tuple[MailleCoefficient, ClasseEcart | None]] = []

    def _archiver(
        conn: Any,
        maille: MailleCoefficient,
        classe_ecart: ClasseEcart | None = None,
        ecart: float | None = None,
        ecart_coefficient: str | None = None,
    ) -> None:
        archivees.append((maille, classe_ecart))

    monkeypatch.setattr(orchestration, "archiver_coefficient", _archiver)
    return archivees


def test_le_pool_du_departement_n_est_charge_qu_une_fois(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Recharger par commune multiplierait les requêtes sans rien apporter (§10.6)."""
    chargements: list[str] = []

    def _charger(conn: Any, departement: str, millesime: int, **_: Any) -> list[Mutation]:
        chargements.append(departement)
        return _POOL

    monkeypatch.setattr(orchestration, "load_pool", _charger)

    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert chargements == ["31"]


def test_le_pool_est_charge_sur_la_fenetre_de_millesimes(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """La fenêtre glissante (#177) est pilotée par la config et transmise au pool.

    Le millésime publié reste le millésime de référence (N) ; seule la profondeur
    d'estimation change. L'orchestration ne connaît pas la requête SQL, elle
    transmet la taille de fenêtre lue dans la config.
    """
    fenetres: list[int] = []

    def _charger(
        conn: Any, departement: str, millesime: int, *, fenetre: int = 1, **_: Any
    ) -> list[Mutation]:
        fenetres.append(fenetre)
        return _POOL

    monkeypatch.setattr(orchestration, "load_pool", _charger)

    config = resolve_config({"VV_MOTEUR_FENETRE_MILLESIMES": "3"})
    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024, config=config)

    assert fenetres == [3]


def test_le_pool_deborde_sur_les_departements_limitrophes(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Les communes du bord doivent voir les ventes d'en face (#18)."""
    appels: list[tuple[str, tuple[str, ...]]] = []

    def _charger(
        conn: Any,
        departement: str,
        millesime: int,
        *,
        fenetre: int = 1,
        limitrophes: tuple[str, ...] = (),
        type_local: str = "Maison",
    ) -> list[Mutation]:
        appels.append((departement, limitrophes))
        return _POOL

    monkeypatch.setattr(orchestration, "load_pool", _charger)

    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert appels == [("31", ("09", "11", "32", "65", "81", "82"))]


def test_le_calcul_ecarte_les_dpe_d_un_regime_de_reforme_anterieur(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """#215 : avec un calendrier de réformes, le pool est filtré avant calcul —
    les ventes dont le DPE précède la dernière réforme ≤ N n'entrent pas."""
    ancien = replace(_vente(1, "D", 200000.0), date_etablissement_dpe="2023-05-01")
    recent = replace(_vente(2, "D", 200000.0), date_etablissement_dpe="2024-09-01")
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: [ancien, recent])

    recu: list[Mutation] = []

    def _capturer(
        coordonnees: Any, pool: Sequence[Mutation], millesime: int, *, config: CalculConfig
    ) -> list[MailleCoefficient]:
        recu.extend(pool)
        return []

    monkeypatch.setattr(orchestration, "compute_communes", _capturer)

    config = replace(CalculConfig(), dpe_reform_boundaries=("2024-07-01",))
    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024, config=config)

    assert [vente.id_mutation for vente in recu] == ["m2"]


def test_seules_les_communes_du_departement_recoivent_un_coefficient(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Élargir le pool ne doit pas élargir la publication.

    Une commune limitrophe est dans le pool pour *nourrir* le calcul du 31,
    pas pour être calculée : son propre disque déborderait sur des
    départements non chargés, et son coefficient reposerait sur un échantillon
    amputé. La liste des communes vient du découpage administratif du
    département demandé, jamais du pool.
    """
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: _POOL + [_vente_ariegeoise()])
    _neutraliser_monotonie(monkeypatch)
    _neutraliser_signe_ordre(monkeypatch)

    orchestration.compute_departement(
        _FauxFetcher(), connexion, "31", 2024, config=_CONFIG_PUBLIANTE
    )

    ecrits = [str(params[0]) for _, params in connexion.ecritures if params]
    assert ecrits, "le test ne prouve rien si rien n'est écrit"
    assert all(code.startswith("31") for code in ecrits), ecrits


def test_les_communes_calculees_sont_ecrites(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Un coefficient calculé mais non écrit ne servirait à rien au widget.

    REG-2 (#130) : l'écriture va dans l'archive, c'est elle que la publication
    projettera vers le service — une maille calculée puis non archivée serait perdue.
    """
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: _POOL)
    _neutraliser_monotonie(monkeypatch)
    _neutraliser_signe_ordre(monkeypatch)

    rapport = orchestration.compute_departement(
        _FauxFetcher(), connexion, "31", 2024, config=_CONFIG_PUBLIANTE
    )

    assert rapport.communes_calculees > 0
    assert len(_archivees(connexion)) == rapport.communes_calculees
    assert connexion.commits >= 1


def test_le_rapport_journalise_l_ordre_canonique_deterministe() -> None:
    """INV-5 (#123) : le rapport de batch trace la garantie de reproductibilité —
    la clé d'ordre canonique qui rend le calcul déterministe. Sans ce marqueur,
    un millésime publié n'est pas auditable et REG-1 compare des batches dont on
    ne sait pas s'ils étaient reproductibles.
    """
    rapport = orchestration.RapportCalcul(departement="31", millesime=2024)

    assert rapport.ordre_canonique == "id_mutation"
    assert "id_mutation" in rapport.resume()


def test_le_resume_surface_les_ecarts_reg1() -> None:
    """L'opérateur doit voir, dans le résumé du batch, combien de mailles ont été
    écartées ou signalées par REG-1 — sinon les suspensions passent inaperçues.

    Le batch ne publiant plus rien (REG-2, #130), le libellé parle d'écartement de
    la publication et non de publication bloquée : rien n'a été publié.
    """
    rapport = orchestration.RapportCalcul(departement="31", millesime=2024)
    rapport.ecarts.ajouter("31555", EcartMaille(ClasseEcart.BLOCAGE, 0.30, "élasticité bâti"))
    rapport.ecarts.ajouter("31149", EcartMaille(ClasseEcart.ALERTE, 0.15, "DPE F"))

    resume = rapport.resume()

    assert "1 maille(s) écartée(s) de la publication" in resume
    assert "1 alerte(s) d'écart" in resume


def test_une_commune_sous_le_seuil_est_refusee_sans_erreur(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Le refus sous seuil est un résultat du cadrage (§7), pas une panne."""
    connexion = _FausseConnexion([])
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: [])

    rapport = orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert rapport.communes_calculees == 0
    assert rapport.refus == ["31555", "31149"]
    assert rapport.echecs == []


def test_un_departement_sans_aucun_coefficient_sort_en_erreur(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Rien de calculé signale un pool vide ou un millésime erroné."""
    connexion = _FausseConnexion([])
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: [])

    rapport = orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert rapport.est_en_echec


def test_la_commande_compute_exige_departement_et_millesime() -> None:
    with pytest.raises(SystemExit):
        cli.main(["compute", "--departement", "31"])


def test_une_commune_devenue_non_calculable_perd_son_coefficient(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Sinon un coefficient périmé continue d'être servi (#115).

    Constaté sur le 31 : après durcissement du seuil, 203 mailles sont
    refusées mais la table en comptait toujours 586 — l'upsert ne supprime
    rien, donc les anciennes valeurs survivaient au recalcul qui les invalide.

    REG-2 (#130) : le refus est **différé**, pas perdu — le batch l'inscrit au
    registre et c'est la publication du millésime qui retirera la commune du
    service. Un refus non enregistré, lui, laisserait le coefficient périmé servi
    indéfiniment.
    """
    connexion = _FausseConnexion([])
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: [])

    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    refus = _refus_notes(connexion)
    assert len(refus) == 2, "chaque commune refusée doit être inscrite au registre des refus"
    assert [params[2] for params in refus] == ["refus", "refus"]


def test_une_maille_hors_seuils_de_qualite_n_est_pas_publiee(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """INV-4 (#122) : une maille calculée mais sous les seuils de qualité n'est pas
    écrite ; elle est comptée en rejet d'invariant, pas en coefficient publié.

    Le rejet a lieu **avant** l'archivage (REG-2, #130) : la maille n'atteint donc
    même pas l'historique — seul son refus est noté.

    Seuil R² porté à 0,99 (impossible) : toute maille calculée est rejetée.
    """
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: _POOL)
    config = replace(CalculConfig(), r2_min=0.99)

    rapport = orchestration.compute_departement(
        _FauxFetcher(), connexion, "31", 2024, config=config
    )

    assert _archivees(connexion) == []
    assert rapport.communes_calculees == 0
    assert set(rapport.rejets_invariants) == {"31555", "31149"}


def test_une_commune_sans_local_publiable_bascule_au_cran_epci(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """#169 cascade : quand le LOCAL ne produit rien de publiable, la commune
    descend au cran EPCI (ventes des communes du même EPCI) avant de refuser.
    31555 a un EPCI publiable → archivée en mode EPCI ; 31149 sans EPCI → refus.
    """
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: _POOL)
    monkeypatch.setattr(orchestration, "compute_communes", lambda *a, **k: iter([]))
    monkeypatch.setattr(
        orchestration,
        "communes_de_meme_epci",
        lambda conn, code: ("31555", "31149") if code == "31555" else (),
    )
    maille_epci = replace(_maille_publiable("31555"), mode=CoverageMode.EPCI, rayon_retenu_km=None)
    monkeypatch.setattr(
        orchestration,
        "compute_maille_epci",
        lambda code, mil, membres, pool, config: maille_epci if code == "31555" else None,
    )
    archivees = _capturer_archives(monkeypatch)

    rapport = orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert [m.code_insee for m, _ in archivees] == ["31555"]
    assert archivees[0][0].mode is CoverageMode.EPCI
    assert rapport.communes_calculees == 1
    assert rapport.refus == ["31149"]


def test_le_cran_departemental_est_calcule_une_fois_et_partage(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """#175 : le cran DÉPARTEMENT est la **même** régression pour toutes les communes
    du département (échantillon identique, pondération uniforme). On le calcule **une
    seule fois** et on le réutilise (seul ``code_insee`` change), au lieu de refaire
    fit + ``bandes_monotones`` par commune — le coût qui faisait exploser le 31.
    """
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: _POOL)
    monkeypatch.setattr(orchestration, "compute_communes", lambda *a, **k: iter([]))
    monkeypatch.setattr(orchestration, "communes_de_meme_epci", lambda conn, code: ())
    monkeypatch.setattr(orchestration, "compute_maille_epci", lambda *a, **k: None)

    appels: list[str] = []
    modele = replace(
        _maille_publiable("XXX"), mode=CoverageMode.DEPARTEMENTAL, rayon_retenu_km=None
    )

    def _departement(
        code: str, millesime: int, pool: Sequence[Mutation], *, config: CalculConfig
    ) -> MailleCoefficient:
        appels.append(code)
        return replace(modele, code_insee=code)

    monkeypatch.setattr(orchestration, "compute_maille_departement", _departement)
    archivees = _capturer_archives(monkeypatch)

    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert len(appels) == 1, "une seule régression départementale pour tout le département"
    assert {m.code_insee for m, _ in archivees} == {"31555", "31149"}
    assert all(m.mode is CoverageMode.DEPARTEMENTAL for m, _ in archivees)
    # Coefficients partagés : seul code_insee diffère d'une commune à l'autre.
    assert archivees[0][0].coef_dpe == archivees[1][0].coef_dpe
    assert archivees[0][0].coef_m2_bati == archivees[1][0].coef_m2_bati


def test_le_cran_epci_est_memoise_par_epci(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """#175 : la régression EPCI est identique pour toutes les communes d'un même
    EPCI. On la calcule **une fois par EPCI** et on la réutilise pour ses communes.
    """
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: _POOL)
    monkeypatch.setattr(orchestration, "compute_communes", lambda *a, **k: iter([]))
    # Les deux communes appartiennent au même EPCI (même appartenance renvoyée).
    monkeypatch.setattr(
        orchestration, "communes_de_meme_epci", lambda conn, code: ("31555", "31149")
    )

    appels: list[tuple[str, ...]] = []
    modele = replace(_maille_publiable("XXX"), mode=CoverageMode.EPCI, rayon_retenu_km=None)

    def _epci(
        code: str,
        millesime: int,
        membres: Sequence[str],
        pool: Sequence[Mutation],
        *,
        config: CalculConfig,
    ) -> MailleCoefficient:
        appels.append(tuple(membres))
        return replace(modele, code_insee=code)

    monkeypatch.setattr(orchestration, "compute_maille_epci", _epci)
    archivees = _capturer_archives(monkeypatch)

    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert len(appels) == 1, "une seule régression EPCI réutilisée pour ses deux communes"
    assert {m.code_insee for m, _ in archivees} == {"31555", "31149"}
    assert all(m.mode is CoverageMode.EPCI for m, _ in archivees)


def test_une_maille_rejetee_efface_le_coefficient_anterieur(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Un coefficient publié qui devient non conforme ne doit plus être servi (#115).

    REG-2 (#130) : le rejet d'invariant est noté au registre des refus (motif
    ``rejet_invariant``, distinct du refus sec) et c'est la publication du millésime
    qui effacera le coefficient antérieur.
    """
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: _POOL)
    config = replace(CalculConfig(), r2_min=0.99)

    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024, config=config)

    refus = _refus_notes(connexion)
    assert len(refus) == 2
    assert [params[2] for params in refus] == ["rejet_invariant", "rejet_invariant"]


def test_le_rapport_de_calcul_porte_le_departement_et_le_millesime(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Le rapport identifie sans ambiguïté le batch qu'il décrit."""
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: [])

    rapport = orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert rapport.departement == "31"
    assert rapport.millesime == 2024


def test_un_echec_d_ecriture_est_trace_et_annule(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Une écriture qui échoue est annulée (rollback) et tracée par son code commune,
    sans interrompre les autres — la maille n'est pas comptée comme calculée."""
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: _POOL)
    _neutraliser_monotonie(monkeypatch)
    _neutraliser_signe_ordre(monkeypatch)

    def _boom(*_: Any, **__: Any) -> None:
        raise RuntimeError("base indisponible")

    monkeypatch.setattr(orchestration, "archiver_coefficient", _boom)

    rapport = orchestration.compute_departement(
        _FauxFetcher(), connexion, "31", 2024, config=_CONFIG_PUBLIANTE
    )

    assert set(rapport.echecs) == {"31555", "31149"}
    assert rapport.communes_calculees == 0
    assert connexion.rollbacks >= 1


def _maille_publiable(code_insee: str, coef_m2_bati: float = 0.90) -> MailleCoefficient:
    """Maille saine (passe INV-2/INV-3 #1/INV-4). ``coef_m2_bati`` est une
    élasticité (log-prix), pilotée pour tester la rupture INV-3 #3."""
    return MailleCoefficient(
        code_insee=code_insee,
        millesime=2024,
        mode=CoverageMode.LOCAL,
        rayon_retenu_km=10,
        n_transactions=40,
        r2=0.5,
        erreur_mediane=15.0,
        coef_m2_bati=coef_m2_bati,
        coef_m2_terrain=0.30,
        coef_dpe={"D": 0.0, "F": -0.20},
        ic_dpe={},
        alerte=None,
    )


def test_rupture_limitrophe_publie_avec_alerte(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """INV-3 #3 : deux communes limitrophes au coef_m2_bati trop écarté restent
    retenues pour la publication, mais reçoivent un bandeau d'alerte de bord
    (facteur affiché) — l'alerte est portée par la maille archivée."""
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: [])
    monkeypatch.setattr(
        orchestration,
        "compute_communes",
        lambda *a, **k: [_maille_publiable("31555", 0.5), _maille_publiable("31149", 1.2)],
    )
    monkeypatch.setattr(
        orchestration,
        "read_adjacence",
        lambda conn, dep: {"31555": ("31149",), "31149": ("31555",)},
    )
    archivees = _capturer_archives(monkeypatch)

    rapport = orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    par_code = {m.code_insee: m for m, _ in archivees}
    assert set(par_code) == {"31555", "31149"}  # les deux restent retenues
    assert "facteur" in (par_code["31555"].alerte or "")
    assert "facteur" in (par_code["31149"].alerte or "")
    assert set(rapport.alertes_bord) == {"31555", "31149"}


def test_sans_rupture_aucune_alerte_de_bord(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: [])
    monkeypatch.setattr(
        orchestration,
        "compute_communes",
        lambda *a, **k: [_maille_publiable("31555", 0.5), _maille_publiable("31149", 0.6)],
    )
    monkeypatch.setattr(
        orchestration,
        "read_adjacence",
        lambda conn, dep: {"31555": ("31149",), "31149": ("31555",)},
    )
    archivees = _capturer_archives(monkeypatch)

    rapport = orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert archivees, "le test ne prouve rien si aucune maille n'est archivée"
    assert all(m.alerte is None for m, _ in archivees)
    assert rapport.alertes_bord == []


def test_maille_hors_plafond_de_rayon_n_est_pas_publiee(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """INV-3 #1 câblé au gate de publication : rayon > plafond → non publiée.

    Le rejet précède l'archivage (REG-2, #130) : la maille n'entre pas dans
    l'historique, donc la publication ne pourra jamais la reprendre.
    """
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: [])
    monkeypatch.setattr(
        orchestration, "compute_communes", lambda *a, **k: [_maille_publiable("31555", 1000.0)]
    )
    monkeypatch.setattr(orchestration, "read_adjacence", lambda *_: {})
    config = replace(CalculConfig(), rayon_max_km=5)  # la maille (rayon 10) dépasse le plafond

    rapport = orchestration.compute_departement(
        _FauxFetcher(), connexion, "31", 2024, config=config
    )

    assert _archivees(connexion) == []
    assert "31555" in rapport.rejets_invariants


def _maille_dpe(
    code_insee: str, coef_dpe: dict[str, float], effectifs_dpe: dict[str, int]
) -> MailleCoefficient:
    # Élasticité par défaut (valide INV-2) : ce témoin isole le comportement INV-1.
    return replace(_maille_publiable(code_insee), coef_dpe=coef_dpe, effectifs_dpe=effectifs_dpe)


def test_inversion_dpe_bloquante_n_est_pas_publiee(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """INV-1 câblé au gate : F/G inversées et toutes deux fournies → non publiée.

    Le rejet précède l'archivage (REG-2, #130) : la maille n'entre pas dans
    l'historique, donc la publication ne pourra jamais la reprendre.
    """
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: [])
    monkeypatch.setattr(
        orchestration,
        "compute_communes",
        lambda *a, **k: [_maille_dpe("31555", {"F": -0.30, "G": -0.10}, {"F": 40, "G": 35})],
    )
    monkeypatch.setattr(orchestration, "read_adjacence", lambda *_: {})

    rapport = orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert _archivees(connexion) == []
    assert "31555" in rapport.rejets_invariants


def test_inversion_dpe_toleree_est_publiee_et_journalisee(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """INV-1 : F/G inversées mais G rare (12 < 30) → retenue, mais tracée."""
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: [])
    monkeypatch.setattr(
        orchestration,
        "compute_communes",
        lambda *a, **k: [_maille_dpe("31555", {"F": -0.30, "G": -0.10}, {"F": 40, "G": 12})],
    )
    monkeypatch.setattr(orchestration, "read_adjacence", lambda *_: {})
    archivees = _capturer_archives(monkeypatch)

    rapport = orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert [m.code_insee for m, _ in archivees] == ["31555"]  # retenue pour la publication
    assert "31555" in rapport.inversions_dpe  # journalisée malgré tout
    assert rapport.rejets_invariants == []


def test_maille_dont_l_ecart_depasse_le_seuil_est_archivee_en_blocage(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """REG-1 (#129) : un coefficient qui bouge trop d'un millésime au précédent est
    bloqué. Depuis REG-2 (#130), le blocage n'empêche plus l'**archivage** — il
    empêche l'**inscription à la publication** : la maille est archivée en portant
    ``classe_ecart=BLOCAGE``, et c'est cette classe qui permettra à la publication
    de l'écarter (sans elle, projeter « toutes les lignes du millésime » la
    publierait). Le rapport d'écarts la liste toujours en blocage.
    """
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: _POOL)
    monkeypatch.setattr(
        orchestration, "compute_communes", lambda *a, **k: iter([_maille_publiable("31555")])
    )
    # Précédent à élasticité bâti 0,30 → 0,90 = +200 % : au-delà du blocage (25 %).
    monkeypatch.setattr(
        orchestration,
        "lire_coefficient_precedent",
        lambda conn, code, mil: _maille_publiable(code, 0.30) if code == "31555" else None,
    )
    archivees = _capturer_archives(monkeypatch)

    rapport = orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert [m.code_insee for m, _ in archivees] == ["31555"]  # archivée
    assert [classe for _, classe in archivees] == [ClasseEcart.BLOCAGE]  # mais écartée
    assert [ligne.code_insee for ligne in rapport.ecarts.blocages] == ["31555"]


def test_maille_en_alerte_est_publiee_et_signalee(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Un écart 10-25 % retient la maille pour publication mais la signale (REG-1).

    L'archive porte la classe ALERTE, pas BLOCAGE : la publication la reprendra.
    """
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: _POOL)
    monkeypatch.setattr(
        orchestration, "compute_communes", lambda *a, **k: iter([_maille_publiable("31555")])
    )
    # 0,75 → 0,90 = +20 % : alerte.
    monkeypatch.setattr(
        orchestration,
        "lire_coefficient_precedent",
        lambda conn, code, mil: _maille_publiable(code, 0.75) if code == "31555" else None,
    )
    archivees = _capturer_archives(monkeypatch)

    rapport = orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert [m.code_insee for m, _ in archivees] == ["31555"]  # retenue
    assert [classe for _, classe in archivees] == [ClasseEcart.ALERTE]
    assert [ligne.code_insee for ligne in rapport.ecarts.alertes] == ["31555"]


def test_premiere_publication_sans_precedent_publie_sans_comparer(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Sans millésime antérieur (première publication), rien à comparer : on retient.

    L'archive part sans classe d'écart — absence de verdict, pas verdict défavorable.
    """
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: _POOL)
    monkeypatch.setattr(
        orchestration, "compute_communes", lambda *a, **k: iter([_maille_publiable("31555")])
    )
    monkeypatch.setattr(orchestration, "lire_coefficient_precedent", lambda conn, code, mil: None)
    archivees = _capturer_archives(monkeypatch)

    rapport = orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert [m.code_insee for m, _ in archivees] == ["31555"]
    assert [classe for _, classe in archivees] == [None]
    assert rapport.ecarts.lignes == []


def test_compute_accepte_une_option_rapport_ecarts() -> None:
    """REG-1 : la commande compute peut écrire le rapport d'écarts en CSV."""
    args = cli._analyser(
        ["compute", "--departement", "09", "--millesime", "2024", "--rapport-ecarts", "/tmp/e.csv"]
    )
    assert args.rapport_ecarts == "/tmp/e.csv"


def test_compute_sans_rapport_ecarts_est_none_par_defaut() -> None:
    args = cli._analyser(["compute", "--departement", "09", "--millesime", "2024"])
    assert args.rapport_ecarts is None
