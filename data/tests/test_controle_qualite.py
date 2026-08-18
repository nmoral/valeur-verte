"""Contrôle de validité de ``data.vente`` — cœur pur (audit read-only).

L'audit **constate** des anomalies sur les ventes déjà ingérées ; il ne filtre ni
ne corrige (la base brute S12 est volontaire). Ces tests fixent l'API du module
pur : un prédicat par ligne (``controler_ligne``), la détection de doublons
(``detecter_doublons``, intrinsèquement cross-lignes) et l'agrégation en rapport
(``construire_rapport``) avec verdict et code de sortie. Tout est hors-ligne :
aucune connexion, la couche DB n'est qu'un pourvoyeur de lignes.

Deux sévérités : ``ANOMALIE`` (incohérent, casse le modèle — prix ≤ 0, doublon)
et ``ALERTE`` (suspect mais possiblement légitime — valeur absurde, hors bbox).
"""

from __future__ import annotations

from valeur_verte_data.controle_qualite import (
    Anomalie,
    ControleConfig,
    LigneVente,
    RapportControle,
    Severite,
    construire_rapport,
    controler_ligne,
    detecter_doublons,
)


def _ligne(**overrides: object) -> LigneVente:
    """Ligne saine par défaut ; surcharger un champ pour cibler un contrôle."""
    defaut: dict[str, object] = {
        "id": 1,
        "id_mutation": "2024-000001",
        "millesime": 2024,
        "code_insee": "31555",
        "date_mutation": "2024-05-01",
        "prix": 250_000.0,
        "surface_bati": 100.0,
        "surface_terrain": 400.0,
        "prix_m2": 2_500.0,  # = prix / surface_bati (cohérent)
        "etiquette_dpe": "D",
        "longitude": 1.44,
        "latitude": 43.6,
        "a_geom": True,
    }
    defaut.update(overrides)
    return LigneVente(**defaut)  # type: ignore[arg-type]


def _codes(anomalies: tuple[Anomalie, ...]) -> set[str]:
    return {anomalie.code for anomalie in anomalies}


# --- Ligne saine ------------------------------------------------------------


def test_ligne_saine_aucune_anomalie() -> None:
    assert controler_ligne(_ligne()) == ()


# --- Cohérence intra-mutation ----------------------------------------------


def test_prix_nul_ou_negatif_est_une_anomalie() -> None:
    for prix in (0.0, -1.0, None):
        anomalies = controler_ligne(_ligne(prix=prix, prix_m2=None))
        fautives = [a for a in anomalies if a.code == "prix_non_positif"]
        assert fautives
        assert all(a.severite is Severite.ANOMALIE for a in fautives)


def test_surface_bati_nulle_ou_negative_est_une_anomalie() -> None:
    for bati in (0.0, -10.0, None):
        codes = _codes(controler_ligne(_ligne(surface_bati=bati, prix_m2=None)))
        assert "bati_non_positif" in codes


def test_prix_m2_incoherent_avec_prix_sur_bati_est_une_alerte() -> None:
    # prix/bati = 2500 ; prix_m2 annoncé à 4000 → écart ~60 % > tolérance.
    anomalies = controler_ligne(_ligne(prix_m2=4_000.0))
    incoherences = [a for a in anomalies if a.code == "prix_m2_incoherent"]
    assert len(incoherences) == 1
    assert incoherences[0].severite is Severite.ALERTE


def test_prix_m2_dans_la_tolerance_ne_declenche_rien() -> None:
    # 2550 vs 2500 attendu = 2 % < tolérance par défaut (5 %).
    assert _codes(controler_ligne(_ligne(prix_m2=2_550.0))) == set()


def test_prix_m2_absent_ne_declenche_pas_l_incoherence() -> None:
    assert "prix_m2_incoherent" not in _codes(controler_ligne(_ligne(prix_m2=None)))


# --- Tailles bâti / terrain -------------------------------------------------


def test_terrain_negatif_est_une_anomalie() -> None:
    codes = _codes(controler_ligne(_ligne(surface_terrain=-5.0)))
    assert "terrain_negatif" in codes


def test_terrain_absent_ou_nul_est_legitime() -> None:
    for terrain in (0.0, None):
        assert _codes(controler_ligne(_ligne(surface_terrain=terrain))) == set()


def test_bati_au_dela_du_plafond_absurde_est_une_alerte() -> None:
    anomalie = controler_ligne(_ligne(surface_bati=5_000.0, prix_m2=None))
    absurdes = [a for a in anomalie if a.code == "bati_absurde"]
    assert len(absurdes) == 1
    assert absurdes[0].severite is Severite.ALERTE


def test_terrain_au_dela_du_plafond_absurde_est_une_alerte() -> None:
    codes = _codes(controler_ligne(_ligne(surface_terrain=2_000_000.0)))
    assert "terrain_absurde" in codes


# --- Géo & référentiel ------------------------------------------------------


def test_coordonnees_presentes_sans_geom_est_une_anomalie() -> None:
    codes = _codes(controler_ligne(_ligne(a_geom=False)))
    assert "geom_manquant" in codes


def test_geom_present_sans_coordonnees_est_une_anomalie() -> None:
    codes = _codes(controler_ligne(_ligne(longitude=None, latitude=None, a_geom=True)))
    assert "coordonnees_manquantes" in codes


def test_ni_geom_ni_coordonnees_ne_declenche_rien() -> None:
    # Une vente non géolocalisée est légitime (elle sort du calcul, pas polluante).
    assert _codes(controler_ligne(_ligne(longitude=None, latitude=None, a_geom=False))) == set()


def test_coordonnees_hors_bbox_metropole_est_une_alerte() -> None:
    anomalies = controler_ligne(_ligne(longitude=55.5, latitude=-21.1))  # La Réunion
    hors = [a for a in anomalies if a.code == "hors_bbox"]
    assert len(hors) == 1
    assert hors[0].severite is Severite.ALERTE


def test_etiquette_dpe_invalide_est_une_alerte() -> None:
    codes = _codes(controler_ligne(_ligne(etiquette_dpe="Z")))
    assert "dpe_invalide" in codes


def test_etiquette_dpe_valide_ou_absente_ne_declenche_rien() -> None:
    for dpe in ("A", "G", None):
        assert "dpe_invalide" not in _codes(controler_ligne(_ligne(etiquette_dpe=dpe)))


def test_commune_orpheline_du_referentiel_est_une_alerte() -> None:
    codes = _codes(controler_ligne(_ligne(code_insee="99999"), communes_connues={"31555"}))
    assert "commune_orpheline" in codes


def test_commune_connue_ne_declenche_rien() -> None:
    assert "commune_orpheline" not in _codes(
        controler_ligne(_ligne(code_insee="31555"), communes_connues={"31555"})
    )


def test_referentiel_absent_n_evalue_pas_l_orphelinage() -> None:
    # Sans référentiel fourni, on ne peut pas juger l'appartenance : contrôle muet.
    assert "commune_orpheline" not in _codes(controler_ligne(_ligne(code_insee="99999")))


def test_annee_de_date_mutation_differente_du_millesime_est_une_alerte() -> None:
    codes = _codes(controler_ligne(_ligne(date_mutation="2019-03-02", millesime=2024)))
    assert "millesime_incoherent" in codes


# --- Configuration des seuils ----------------------------------------------


def test_les_plafonds_de_vraisemblance_sont_configurables() -> None:
    stricte = ControleConfig(bati_max_absurde=90.0)
    assert "bati_absurde" in _codes(controler_ligne(_ligne(surface_bati=100.0), config=stricte))


# --- Doublons (cross-lignes) ------------------------------------------------


def test_mutation_unique_ne_produit_aucun_doublon() -> None:
    assert detecter_doublons([_ligne(id=1), _ligne(id=2, id_mutation="2024-000002")]) == ()


def test_id_mutation_repete_sur_un_millesime_est_une_anomalie() -> None:
    # Cas typique : chevauchement inter-départements (vente de bordure ingérée 2×).
    doublons = detecter_doublons([_ligne(id=1), _ligne(id=2)])
    assert len(doublons) == 1
    assert doublons[0].code == "doublon"
    assert doublons[0].severite is Severite.ANOMALIE


def test_doublon_aux_valeurs_divergentes_est_qualifie() -> None:
    doublons = detecter_doublons([_ligne(id=1, prix=250_000.0), _ligne(id=2, prix=300_000.0)])
    assert len(doublons) == 1
    assert doublons[0].code == "doublon_divergent"
    assert doublons[0].severite is Severite.ANOMALIE


def test_meme_id_mutation_sur_millesimes_distincts_n_est_pas_un_doublon() -> None:
    # La clé de doublon est (id_mutation, millesime) : deux millésimes ⇒ deux ventes.
    lignes = [_ligne(id=1, millesime=2023), _ligne(id=2, millesime=2024)]
    assert detecter_doublons(lignes) == ()


def test_doublons_tries_de_maniere_deterministe() -> None:
    lignes = [
        _ligne(id=1, id_mutation="2024-000002"),
        _ligne(id=2, id_mutation="2024-000002"),
        _ligne(id=3, id_mutation="2024-000001"),
        _ligne(id=4, id_mutation="2024-000001"),
    ]
    refs = [a.reference for a in detecter_doublons(lignes)]
    assert refs == sorted(refs)


# --- Agrégation en rapport --------------------------------------------------


def _anomalie(code: str, severite: Severite, reference: str = "vente#1") -> Anomalie:
    return Anomalie(code=code, severite=severite, message=code, reference=reference)


def test_rapport_compte_anomalies_et_alertes() -> None:
    anomalies = (
        _anomalie("prix_non_positif", Severite.ANOMALIE),
        _anomalie("hors_bbox", Severite.ALERTE),
        _anomalie("dpe_invalide", Severite.ALERTE),
    )
    rapport = construire_rapport(anomalies, lignes_controlees=10)
    assert rapport.n_anomalies == 1
    assert rapport.n_alertes == 2
    assert rapport.lignes_controlees == 10


def test_rapport_valide_ssi_aucune_anomalie() -> None:
    sain = construire_rapport((_anomalie("hors_bbox", Severite.ALERTE),), lignes_controlees=1)
    pollue = construire_rapport((_anomalie("doublon", Severite.ANOMALIE),), lignes_controlees=1)
    assert sain.valide is True  # les alertes ne font pas échouer
    assert pollue.valide is False


def test_rapport_ordonne_les_anomalies_avant_les_alertes() -> None:
    entree = (
        _anomalie("hors_bbox", Severite.ALERTE, "vente#9"),
        _anomalie("doublon", Severite.ANOMALIE, "vente#5"),
    )
    rapport = construire_rapport(entree, lignes_controlees=2)
    assert rapport.anomalies[0].severite is Severite.ANOMALIE
    assert rapport.anomalies[1].severite is Severite.ALERTE


def test_resume_mentionne_les_compteurs() -> None:
    rapport = construire_rapport((_anomalie("doublon", Severite.ANOMALIE),), lignes_controlees=42)
    resume = rapport.resume()
    assert "42" in resume
    assert "1" in resume


def test_rapport_est_un_dataclass_gele() -> None:
    rapport = construire_rapport((), lignes_controlees=0)
    assert isinstance(rapport, RapportControle)
    assert rapport.valide is True
