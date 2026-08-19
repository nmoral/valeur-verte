"""#262 — garde dur du pooling : ne pas régulariser vers un prior départemental biaisé.

Le shrinkage (#249) tire chaque effet communal vers le fit poolé départemental ``μ``.
L'hypothèse « le prior est plus fiable que l'observation locale » vaut pour la
**variance**, pas pour le **biais** : un μ franchement cassé propagerait son anomalie à
toutes les mailles rurales régularisées.

Garde **lenient** : μ est un prior fiable tant qu'il est **publiable** au sens d'INV-1
(pas d'inversion DPE *bloquante* — une inversion **tolérée** est réputée réelle #115,
donc on lui fait confiance) **et** d'INV-2 (signes/ordres). On ne coupe le pooling que
sur un μ *vraiment* anormal ; sinon on garde le bénéfice du shrinkage.
"""

from __future__ import annotations

from valeur_verte_data.batch import MailleCoefficient
from valeur_verte_data.config import DEFAULT_CONFIG
from valeur_verte_data.degraded import CoverageMode, PalierFiabilite
from valeur_verte_data.orchestration import prior_pooling_fiable


def _cible(
    coef_dpe: dict[str, float],
    effectifs: dict[str, int],
    *,
    coef_m2_bati: float = 0.9,
    coef_m2_terrain: float = 0.05,
) -> MailleCoefficient:
    return MailleCoefficient(
        code_insee="31000",
        millesime=2025,
        mode=CoverageMode.DEPARTEMENTAL,
        rayon_retenu_km=None,
        n_transactions=500,
        r2=0.5,
        erreur_mediane=15.0,
        coef_m2_bati=coef_m2_bati,
        coef_m2_terrain=coef_m2_terrain,
        coef_dpe=coef_dpe,
        ic_dpe={},
        alerte=None,
        palier=PalierFiabilite.STANDARD,
        effectifs_dpe=effectifs,
    )


def test_prior_monotone_et_valide_est_fiable() -> None:
    cible = _cible({"C": 0.10, "D": 0.0, "E": -0.20}, {"C": 60, "D": 200, "E": 60})

    assert prior_pooling_fiable(cible, DEFAULT_CONFIG) is True


def test_prior_avec_inversion_toleree_reste_fiable() -> None:
    # D et E adjacentes, coef(D)=0 < coef(E)=0,10 ; E sous le seuil d'effectif (10 < 30)
    # → inversion TOLÉRÉE par INV-1 (réputée réelle #115). Lenient : μ reste fiable.
    cible = _cible({"D": 0.0, "E": 0.10}, {"D": 200, "E": 10})

    assert prior_pooling_fiable(cible, DEFAULT_CONFIG) is True


def test_prior_avec_inversion_bloquante_n_est_pas_fiable() -> None:
    # Même inversion mais E bien fournie (60 ≥ 30) → NON tolérée → bloquante : μ n'est
    # pas publiable (INV-1), on ne régularise pas vers lui (#262).
    cible = _cible({"D": 0.0, "E": 0.10}, {"D": 200, "E": 60})

    assert prior_pooling_fiable(cible, DEFAULT_CONFIG) is False


def test_prior_qui_viole_inv2_n_est_pas_fiable() -> None:
    # Monotone, mais élasticité bâti hors bornes INV-2 (> 1,50) : signe/ordre KO.
    cible = _cible({"C": 0.10, "D": 0.0, "E": -0.20}, {"C": 60, "D": 200, "E": 60}, coef_m2_bati=2.0)

    assert prior_pooling_fiable(cible, DEFAULT_CONFIG) is False
