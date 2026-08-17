"""DAT-1 (#124) — contrat de schéma des sources ingérées.

Le pipeline lit des fichiers publics qui changent de format sans préavis. Une
colonne DVF renommée ne lève aucune erreur : `.get()` renvoie ``None`` et le
défaut se propage silencieusement. Ces tests couvrent la logique **pure** du
garde-fou : la liste versionnée des colonnes attendues par source, l'échec
explicite nommant la colonne manquante, et la détection d'une valeur de
`periode_construction` hors du vocabulaire connu.
"""

from __future__ import annotations

import pytest

from valeur_verte_data import dpe_source
from valeur_verte_data.schema_source import (
    COLONNES_COMMUNE,
    COLONNES_DPE,
    COLONNES_DVF,
    VOCABULAIRE_PERIODE_CONSTRUCTION,
    SchemaSourceError,
    periodes_inconnues,
    verifier_colonnes,
    verifier_schema_dvf,
    verifier_schema_json,
)


def test_verifier_colonnes_passe_quand_toutes_presentes() -> None:
    # Une colonne surnuméraire est tolérée : la source peut en ajouter.
    verifier_colonnes("DVF", {"a", "b"}, {"a", "b", "c"})


def test_verifier_colonnes_echoue_en_nommant_la_colonne_manquante() -> None:
    with pytest.raises(SchemaSourceError) as info:
        verifier_colonnes("DVF", {"valeur_fonciere", "code_commune"}, {"code_commune"})

    erreur = info.value
    assert erreur.source == "DVF"
    assert erreur.colonnes_manquantes == frozenset({"valeur_fonciere"})
    # Le message porte la source ET le nom exact de la colonne manquante.
    assert "DVF" in str(erreur)
    assert "valeur_fonciere" in str(erreur)


def test_verifier_colonnes_nomme_toutes_les_manquantes_triees() -> None:
    with pytest.raises(SchemaSourceError) as info:
        verifier_colonnes("DPE", {"etiquette_dpe", "adresse_ban", "version_dpe"}, set())

    message = str(info.value)
    # Toutes nommées, dans un ordre stable (trié) pour un message reproductible.
    assert (
        message.index("adresse_ban") < message.index("etiquette_dpe") < message.index("version_dpe")
    )


def test_verifier_schema_dvf_accepte_un_entete_complet() -> None:
    entete = ",".join(sorted(COLONNES_DVF))
    verifier_schema_dvf(entete + "\n2024-1,300000\n")


def test_verifier_schema_dvf_rejette_une_colonne_renommee() -> None:
    # `valeur_fonciere` renommée `valeur` : cas réel d'un changement de format DVF.
    colonnes = (COLONNES_DVF - {"valeur_fonciere"}) | {"valeur"}
    entete = ",".join(sorted(colonnes))

    with pytest.raises(SchemaSourceError) as info:
        verifier_schema_dvf(entete + "\n")

    assert info.value.source == "DVF"
    assert "valeur_fonciere" in info.value.colonnes_manquantes


def test_verifier_schema_dvf_ignore_un_texte_sans_entete() -> None:
    # Corps vide (aucune ligne, aucun en-tête) : rien d'observé, rien à juger.
    verifier_schema_dvf("")


def test_verifier_schema_json_valide_sur_l_union_des_cles() -> None:
    # Un champ absent d'un enregistrement mais présent d'un autre reste connu :
    # l'API omet les champs nuls (une commune sans EPCI), ce n'est pas un renommage.
    enregistrements = [
        {"code": "1", "nom": "A", "codeDepartement": "31", "centre": {}, "codesPostaux": []},
        {"code": "2", "nom": "B", "codeDepartement": "31", "codeEpci": "200", "centre": {}},
    ]

    verifier_schema_json("Découpage", COLONNES_COMMUNE, enregistrements)


def test_verifier_schema_json_rejette_un_champ_absent_de_tous() -> None:
    # `codeEpci` absent de TOUS les enregistrements = champ renommé/supprimé.
    enregistrements = [
        {"code": "1", "nom": "A", "codeDepartement": "31", "centre": {}, "codesPostaux": []},
        {"code": "2", "nom": "B", "codeDepartement": "31", "centre": {}, "codesPostaux": []},
    ]

    with pytest.raises(SchemaSourceError) as info:
        verifier_schema_json("Découpage", COLONNES_COMMUNE, enregistrements)

    assert "codeEpci" in info.value.colonnes_manquantes


def test_verifier_schema_json_ignore_une_liste_vide() -> None:
    # Aucun enregistrement (commune sans DPE) : rien d'observé, on ne peut pas
    # distinguer un champ absent d'un champ renommé — on ne juge pas.
    verifier_schema_json("DPE", COLONNES_DPE, [])


def test_periodes_inconnues_vide_quand_vocabulaire_connu() -> None:
    assert periodes_inconnues(["1948-1974", "avant 1948", "après 2021"]) == set()


def test_periodes_inconnues_signale_une_valeur_hors_vocabulaire() -> None:
    assert periodes_inconnues(["1948-1974", "1600-1700"]) == {"1600-1700"}


def test_periodes_inconnues_ignore_les_valeurs_vides() -> None:
    # Une période absente n'est pas une période « nouvelle » : on ne la signale pas.
    # La valeur inconnue *après* le None doit rester détectée (None saute, n'arrête pas).
    assert periodes_inconnues(["", "   ", None, "1948-1974"]) == set()
    assert periodes_inconnues([None, "1600-1700"]) == {"1600-1700"}


def test_periodes_inconnues_normalise_les_espaces() -> None:
    # Un espace de bord ne fait pas d'une valeur connue une valeur nouvelle.
    assert periodes_inconnues([" 1948-1974 "]) == set()


def test_colonnes_dpe_suit_le_mapping_ademe() -> None:
    # Garde-fou anti-dérive : la liste versionnée doit rester alignée sur les
    # champs réellement demandés à l'ADEME (dpe_source._FIELD_MAP).
    assert frozenset(dpe_source._FIELD_MAP.values()) == COLONNES_DPE


def test_les_schemas_couvrent_les_colonnes_critiques() -> None:
    assert {"id_mutation", "valeur_fonciere", "code_commune", "surface_reelle_bati"} <= COLONNES_DVF
    assert {"code", "nom", "codeEpci", "centre"} <= COLONNES_COMMUNE


def test_vocabulaire_periode_non_vide_et_connu() -> None:
    assert "1948-1974" in VOCABULAIRE_PERIODE_CONSTRUCTION
    assert "avant 1948" in VOCABULAIRE_PERIODE_CONSTRUCTION
    assert len(VOCABULAIRE_PERIODE_CONSTRUCTION) >= 5
