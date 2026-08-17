# Jeu de données figé — REG-3 (#56)

Sous-ensemble **gelé** de la base de production, servant de socle aux **cas de
référence** REG-3 : des adresses connues dont les coefficients doivent rester
dans une **fourchette** plausible à chaque déploiement. Contrairement à REG-1
(#129), qui mesure la dérive entre millésimes sur données vivantes, REG-3
attrape la **régression de code/modèle** — celle qui déplace un coefficient à
données constantes. D'où l'exigence d'une entrée **figée** : à jeu identique,
seule une évolution du moteur peut bouger un chiffre.

## `reg3_prod_subset.dump`

Dump `pg_dump -Fc` **`--data-only`**, schéma `data`. Il ne contient **que les
tables lues au calcul** — le test recalcule tout le reste :

| Table | Lignes | Rôle |
|---|---|---|
| `data.vente` | 62 630 | ventes brutes du pool (entrée de la régression) |
| `data.commune_limitrophe` | 8 569 | adjacence communale (INV-3, débordement) |
| `data.commune_epci` | 2 780 | appartenance EPCI (cran EPCI de la cascade #169) |

**Périmètre gelé** : département **31 (Haute-Garonne) + ses 6 limitrophes**
`09, 11, 32, 65, 81, 82`, millésimes **2024 + 2025**. Ces 6 limitrophes sont
*exactement* le pool de production du 31 : recalculer le 31 à N=2025 (fenêtre W2)
sur ce fixture reproduit les coefficients de prod à l'identique (déterminisme
INV-5, #123). Seul le **31** est destiné à être calculé ; les limitrophes ne sont
là que comme pool de débordement.

`commune_contour` est **volontairement exclue** (13 Mo de géométries) : elle ne
sert qu'à *dériver* l'adjacence (`limitrophe_repository`, à l'ingestion), jamais
au calcul. Vérifié : un recalcul du 31 sans les contours donne les 586
coefficients au bit près (mode, palier, erreur, alertes de bord comprises).

## Provenance

Extrait du dump de production **`valeur_verte-france-recompute223-20260816-1940.dump`**
(recompute France entière, N=2025, W2 ; md5 `930c4ab4fae7c699b5d58b0932e847cf`,
identique à `/opt/valeur-verte/dumps/` sur le VPS). Schéma courant post-#211/#215.

Profils couverts sur le recalcul du fixture (N=2025, classes C et E présentes) :

| Profil | mode / palier | communes |
|---|---|---|
| solide | `local` / `standard`, erreur < 14 % | 118 |
| correcte | `local` / `standard`, 14–20 % | 88 |
| fragile | `local` / `degrade`, 20–30 % | 24 |
| repli départemental | `departemental` | 66 |
| (bonus) repli EPCI | `epci` | 160 |

## Régénérer le fixture

Depuis un PostGIS chargé du dump France de prod (`SOURCE_DB`), en ne gardant que
les départements et millésimes du périmètre :

```sql
-- dans une base au schéma courant (migrations appliquées), tables copiées depuis la prod :
--   vente               WHERE left(code_insee,2) IN ('31','09','11','32','65','81','82')
--                         AND millesime IN (2024,2025)
--   commune_epci        WHERE left(code_insee,2) IN (…mêmes 7…)
--   commune_limitrophe  WHERE left(code_insee_a,2) IN (…) OR left(code_insee_b,2) IN (…)
```

```bash
pg_dump -d <base_fixture> --data-only --schema=data \
  -t data.vente -t data.commune_epci -t data.commune_limitrophe -Fc \
  > reg3_prod_subset.dump
```

Le calendrier de réforme DPE (#215) et la pondération temporelle (#208) restent à
leurs **défauts** (désactivés) — le fixture n'active aucun réglage optionnel.
