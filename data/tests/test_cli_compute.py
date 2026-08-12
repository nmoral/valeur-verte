"""CLI de calcul des coefficients par département (#91, §10.6).

Le pool du département est chargé une seule fois, puis chaque commune est
calculée par le moteur et écrite dans ``coefficient_maille``. Une commune sous
le seuil est **refusée** : c'est un résultat normal du cadrage (§7), pas une
erreur, et le rapport doit les distinguer pour rester lisible.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from dataclasses import replace
from typing import Any

import pytest

from valeur_verte_data import cli, orchestration
from valeur_verte_data.batch import MailleCoefficient
from valeur_verte_data.config import CalculConfig
from valeur_verte_data.degraded import CoverageMode
from valeur_verte_data.dvf import Mutation

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
        limitrophes: tuple[str, ...] = (),
        type_local: str = "Maison",
    ) -> list[Mutation]:
        appels.append((departement, limitrophes))
        return _POOL

    monkeypatch.setattr(orchestration, "load_pool", _charger)

    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert appels == [("31", ("09", "11", "32", "65", "81", "82"))]


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

    orchestration.compute_departement(
        _FauxFetcher(), connexion, "31", 2024, config=_CONFIG_PUBLIANTE
    )

    ecrits = [str(params[0]) for _, params in connexion.ecritures if params]
    assert ecrits, "le test ne prouve rien si rien n'est écrit"
    assert all(code.startswith("31") for code in ecrits), ecrits


def test_les_communes_calculees_sont_ecrites(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Un coefficient calculé mais non écrit ne servirait à rien au widget."""
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: _POOL)

    rapport = orchestration.compute_departement(
        _FauxFetcher(), connexion, "31", 2024, config=_CONFIG_PUBLIANTE
    )

    assert rapport.communes_calculees > 0
    assert len(connexion.ecritures) == rapport.communes_calculees
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
    """
    connexion = _FausseConnexion([])
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: [])

    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    suppressions = [r for r in connexion.requetes if r.strip().upper().startswith("DELETE")]
    assert len(suppressions) == 2, "chaque commune refusée doit voir son coefficient effacé"


def test_une_maille_hors_seuils_de_qualite_n_est_pas_publiee(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """INV-4 (#122) : une maille calculée mais sous les seuils de qualité n'est pas
    écrite ; elle est comptée en rejet d'invariant, pas en coefficient publié.

    Seuil R² porté à 0,99 (impossible) : toute maille calculée est rejetée.
    """
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: _POOL)
    config = replace(CalculConfig(), r2_min=0.99)

    rapport = orchestration.compute_departement(
        _FauxFetcher(), connexion, "31", 2024, config=config
    )

    assert connexion.ecritures == []
    assert rapport.communes_calculees == 0
    assert set(rapport.rejets_invariants) == {"31555", "31149"}


def test_une_maille_rejetee_efface_le_coefficient_anterieur(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Un coefficient publié qui devient non conforme ne doit plus être servi (#115)."""
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: _POOL)
    config = replace(CalculConfig(), r2_min=0.99)

    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024, config=config)

    suppressions = [r for r in connexion.requetes if r.strip().upper().startswith("DELETE")]
    assert len(suppressions) == 2


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

    def _boom(*_: Any, **__: Any) -> None:
        raise RuntimeError("base indisponible")

    monkeypatch.setattr(orchestration, "upsert_coefficient", _boom)

    rapport = orchestration.compute_departement(
        _FauxFetcher(), connexion, "31", 2024, config=_CONFIG_PUBLIANTE
    )

    assert set(rapport.echecs) == {"31555", "31149"}
    assert rapport.communes_calculees == 0
    assert connexion.rollbacks >= 1


def _maille_publiable(code_insee: str, coef_m2_bati: float) -> MailleCoefficient:
    """Maille saine (passe INV-4 et INV-3 #1), coef_m2_bati contrôlé pour INV-3 #3."""
    return MailleCoefficient(
        code_insee=code_insee,
        millesime=2024,
        mode=CoverageMode.LOCAL,
        rayon_retenu_km=10,
        n_transactions=40,
        r2=0.5,
        erreur_mediane=15.0,
        coef_m2_bati=coef_m2_bati,
        coef_m2_terrain=100.0,
        coef_dpe={"D": 0.0, "F": -0.20},
        ic_dpe={},
        alerte=None,
    )


def test_rupture_limitrophe_publie_avec_alerte(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """INV-3 #3 : deux communes limitrophes au coef_m2_bati trop écarté restent
    publiées, mais reçoivent un bandeau d'alerte de bord (facteur affiché)."""
    ecrites: list[MailleCoefficient] = []
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: [])
    monkeypatch.setattr(
        orchestration,
        "compute_communes",
        lambda *a, **k: [_maille_publiable("31555", 1000.0), _maille_publiable("31149", 3000.0)],
    )
    monkeypatch.setattr(
        orchestration,
        "read_adjacence",
        lambda conn, dep: {"31555": ("31149",), "31149": ("31555",)},
    )
    monkeypatch.setattr(
        orchestration, "upsert_coefficient", lambda conn, maille: ecrites.append(maille)
    )

    rapport = orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    par_code = {m.code_insee: m for m in ecrites}
    assert set(par_code) == {"31555", "31149"}  # les deux restent publiées
    assert "facteur" in (par_code["31555"].alerte or "")
    assert "facteur" in (par_code["31149"].alerte or "")
    assert set(rapport.alertes_bord) == {"31555", "31149"}


def test_sans_rupture_aucune_alerte_de_bord(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    ecrites: list[MailleCoefficient] = []
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: [])
    monkeypatch.setattr(
        orchestration,
        "compute_communes",
        lambda *a, **k: [_maille_publiable("31555", 1000.0), _maille_publiable("31149", 1500.0)],
    )
    monkeypatch.setattr(
        orchestration,
        "read_adjacence",
        lambda conn, dep: {"31555": ("31149",), "31149": ("31555",)},
    )
    monkeypatch.setattr(
        orchestration, "upsert_coefficient", lambda conn, maille: ecrites.append(maille)
    )

    rapport = orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert all(m.alerte is None for m in ecrites)
    assert rapport.alertes_bord == []


def test_maille_hors_plafond_de_rayon_n_est_pas_publiee(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """INV-3 #1 câblé au gate de publication : rayon > plafond → non publiée."""
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: [])
    monkeypatch.setattr(
        orchestration, "compute_communes", lambda *a, **k: [_maille_publiable("31555", 1000.0)]
    )
    monkeypatch.setattr(orchestration, "read_adjacence", lambda *_: {})
    config = replace(CalculConfig(), rayon_max_km=5)  # la maille (rayon 10) dépasse le plafond

    rapport = orchestration.compute_departement(
        _FauxFetcher(), connexion, "31", 2024, config=config
    )

    assert connexion.ecritures == []
    assert "31555" in rapport.rejets_invariants


def test_compute_departement_derive_la_limitrophie_avant_de_lire(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """INV-3 #3 : la contiguïté est (re)dérivée des contours chargés avant d'être
    lue — sinon l'adjacence refléterait un état périmé (ou vide)."""
    ordre: list[str] = []
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: [])
    monkeypatch.setattr(orchestration, "derive_limitrophes", lambda conn: ordre.append("derive"))
    monkeypatch.setattr(
        orchestration, "read_adjacence", lambda conn, dep: (ordre.append("read"), {})[1]
    )

    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert ordre == ["derive", "read"]
