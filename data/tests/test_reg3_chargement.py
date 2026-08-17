"""REG-3 (#56) — chargement des cas de référence depuis JSON.

Le jeu de cas vit dans un fichier JSON (édité à la main, réécrit par le
calibrateur). Le chargeur le lit en ``CasReference`` typés et **valide aux
frontières** (§Clean Code) : un fichier de recette invalide doit échouer tôt,
avec un message qui **nomme le cas et le problème** — pas planter obscurément au
milieu d'un batch de déploiement.
"""

from __future__ import annotations

import io

import pytest

from valeur_verte_data.degraded import CoverageMode, PalierFiabilite
from valeur_verte_data.reg3 import (
    CasReference,
    CasReferenceInvalide,
    Profil,
    charger_cas_reference,
)


def _json(**champs: object) -> str:
    """Un cas valide par défaut, surchargeable champ par champ, sérialisé en JSON."""
    import json

    cas = {
        "code_insee": "31555",
        "profil": "solide",
        "mode": "local",
        "palier": "standard",
        "n_min": 2000,
        "erreur_max_pct": 15.0,
        "gain_ec_pct": [14.0, 24.0],
    }
    cas.update(champs)
    return json.dumps([cas])


def test_charge_un_cas_minimal_valide() -> None:
    (cas,) = charger_cas_reference(io.StringIO(_json()))
    assert cas == CasReference(
        code_insee="31555",
        profil=Profil.SOLIDE,
        mode_attendu=CoverageMode.LOCAL,
        palier_attendu=PalierFiabilite.STANDARD,
        n_min=2000,
        erreur_max_pct=15.0,
        gain_ec_pct=(14.0, 24.0),
    )


def test_charge_plusieurs_cas_dans_l_ordre() -> None:
    import json

    texte = json.dumps(
        [
            {
                "code_insee": "31555",
                "profil": "solide",
                "mode": "local",
                "palier": "standard",
                "n_min": 2000,
                "erreur_max_pct": 15.0,
                "gain_ec_pct": [14.0, 24.0],
            },
            {
                "code_insee": "31011",
                "profil": "repli_departemental",
                "mode": "departemental",
                "palier": "standard",
                "n_min": 5000,
                "erreur_max_pct": 18.0,
                "gain_ec_pct": [12.0, 24.0],
            },
        ]
    )
    cas = charger_cas_reference(io.StringIO(texte))
    assert [c.code_insee for c in cas] == ["31555", "31011"]
    assert cas[1].profil is Profil.REPLI_DEPARTEMENTAL
    assert cas[1].mode_attendu is CoverageMode.DEPARTEMENTAL


# --- validation aux frontières : enums inconnus ---


def test_profil_inconnu_leve_une_erreur() -> None:
    with pytest.raises(CasReferenceInvalide) as err:
        charger_cas_reference(io.StringIO(_json(profil="bizarre")))
    # Le message nomme le champ, la valeur fautive ET le cas (locatable).
    assert "profil" in str(err.value) and "bizarre" in str(err.value) and "31555" in str(err.value)


def test_mode_inconnu_leve_une_erreur() -> None:
    with pytest.raises(CasReferenceInvalide) as err:
        charger_cas_reference(io.StringIO(_json(mode="orbital")))
    assert "mode" in str(err.value) and "31555" in str(err.value)


def test_palier_inconnu_leve_une_erreur() -> None:
    with pytest.raises(CasReferenceInvalide) as err:
        charger_cas_reference(io.StringIO(_json(palier="parfait")))
    assert "palier" in str(err.value) and "31555" in str(err.value)


# --- validation : fourchette de gain ---


def test_fourchette_desordonnee_leve_une_erreur() -> None:
    # Borne basse au-dessus de la borne haute : fourchette absurde.
    with pytest.raises(CasReferenceInvalide) as err:
        charger_cas_reference(io.StringIO(_json(gain_ec_pct=[24.0, 14.0])))
    assert "gain" in str(err.value) and "31555" in str(err.value)


def test_fourchette_bornes_egales_est_acceptee() -> None:
    # Une fourchette dégénérée [x, x] reste licite (bas ≤ haut) — le calibrateur
    # n'en produit pas, mais la borne d'égalité doit être fixée sans ambiguïté.
    (cas,) = charger_cas_reference(io.StringIO(_json(gain_ec_pct=[18.0, 18.0])))
    assert cas.gain_ec_pct == (18.0, 18.0)


def test_fourchette_de_mauvais_cardinal_leve_une_erreur() -> None:
    with pytest.raises(CasReferenceInvalide) as err:
        charger_cas_reference(io.StringIO(_json(gain_ec_pct=[14.0])))
    assert "31555" in str(err.value)


# --- validation : champ manquant ---


def test_champ_manquant_leve_une_erreur() -> None:
    import json

    incomplet = {"code_insee": "31555", "profil": "solide", "mode": "local"}
    with pytest.raises(CasReferenceInvalide) as err:
        charger_cas_reference(io.StringIO(json.dumps([incomplet])))
    assert "n_min" in str(err.value) or "erreur_max_pct" in str(err.value)
    assert "31555" in str(err.value)


# --- validation : cohérence profil ↔ cran (mode) ---


def test_repli_departemental_exige_le_mode_departemental() -> None:
    with pytest.raises(CasReferenceInvalide) as err:
        charger_cas_reference(io.StringIO(_json(profil="repli_departemental", mode="local")))
    assert "31555" in str(err.value)  # le message nomme le cas en cause


def test_solide_exige_le_mode_local() -> None:
    with pytest.raises(CasReferenceInvalide):
        charger_cas_reference(io.StringIO(_json(profil="solide", mode="epci")))


def test_repli_epci_exige_le_mode_epci() -> None:
    with pytest.raises(CasReferenceInvalide):
        charger_cas_reference(io.StringIO(_json(profil="repli_epci", mode="departemental")))
