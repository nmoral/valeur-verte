# Diagnostic #115 — l'inversion G est circonscrite aux cœurs urbains anciens (maisons)

Complément au [`diagnostic-inversion-FG-toulouse.md`](diagnostic-inversion-FG-toulouse.md)
(mécanisme âge × caractère). Apport permis par le dump
`valeur_verte_2026-08-13_complet.dump`, qui ajoute le **pool Grand Est autour du 52**
(Haute-Marne, franchement rural) aux six limitrophes du 31 : le contraste
**urbain / rural** isole enfin l'origine du biais.

Base : dump du 13/08 restauré en dev (14 départements, millésime 2024 ; coefficients
frais du 12–13/08, r² ≈ 0,65).

## La bonne lentille : ce que le moteur voit réellement

Avant tout chiffre, cadrer l'échantillon de production (config gagnante, cf.
[`RECAP.md`](RECAP.md)), car s'y tromper fausse tout :

- **Maisons seules** — `SEGMENT_MVP = "Maison"` (`db/pool_repository.py`). Décision
  #115 : sur les appartements l'emplacement écrase le DPE (effet de signe opposé).
  Toulouse a `n = 1446` dans `coefficient_maille` = les maisons du rayon 10 km ; un
  pool tous types en donnerait 3 000+.
- **Sans bornes** (S20/S12) — `data.vente` n'est **pas** nettoyée : prix de 1 € à
  16 M€, surface de 1 à 12 654 m², `prix_m2` jusqu'à **232 663 €/m²**. Les outliers
  sont retirés au fit par l'**écrêtage de résidu** (S32 V2, k=3 MAD, n≥100), pas à
  l'ingestion. → **les moyennes sont inexploitables, on raisonne en médiane.**
- **Tous millésimes** — `restreindre_bati_ancien = false` (S20) : le bâti récent
  compte aussi.
- **Classes rares écartées** — `min_class_sample = 20` (S21) : une classe DPE à
  moins de 20 ventes dans l'échantillon n'est **pas publiée** (d'où F/G souvent NULL
  en rural).

## 1. Le signal fiable : le gain E→C tient partout

Sur les coefficients publiés (maisons), `gain E→C = exp(coef_C − coef_E) − 1` :

| Zone | Mailles | gain E→C médian |
|---|---|---|
| Ville ≥ 500 ventes | 1 (Toulouse) | +18 % |
| Bourg 100–499 | 29 | +22 % |
| Rural < 100 | 1 202 | +24 % |

Le cœur de distribution (C, D, E — peuplé, central) donne un gain **monotone et
stable de +18 à +24 %**, de Toulouse au 52 rural. La promesse produit « rénover le
cœur de gamme = gain de valeur » est solide. **Le problème #115 ne touche pas ce
cœur ; il est aux extrêmes (F/G) et en ville.**

## 2. L'inversion G est urbaine, pas systémique

Coefficients publiés (maisons), communes repères :

| Commune | n | r² | E | F | G |
|---|---|---|---|---|---|
| Toulouse `31555` | 1446 | 0,86 | −0,080 | −0,196 | **−0,131** ↰ |
| Dijon `21231` | 625 | 0,85 | −0,128 | −0,151 | **−0,254** ✓ |
| Colomiers `31149` | 1126 | 0,79 | −0,101 | **−0,083** ↰ | −0,097 |
| Rural 52 `52448` | 192 | 0,64 | −0,108 | *(écartée)* | *(écartée)* |

Toulouse porte le rebond **G > F** ; **Dijon**, même taille et même densité, est
**parfaitement monotone**. Ce n'est donc pas « l'urbain » en soi, mais l'urbain
**avec un centre historique cher sur-représenté en G**. En rural, F/G sont le plus
souvent écartées faute d'effectif (S21), et là où elles survivent le rebond ne
touche que **6,5 %** des mailles (contre 33 % des bourgs).

Confirmation par la donnée brute (médiane `€/m²`, maisons) — le 52 est **strictement
décroissant**, Toulouse non :

| DPE | Toulouse méd. | 52 rural méd. |
|---|---|---|
| C | 3 500 | 1 324 |
| D | 3 789 | 1 111 |
| E | 3 595 | 1 046 |
| F | 3 699 | 742 |
| **G** | **4 239** 💥 | **674** |

## 3. Le mécanisme : dans le vieux, G est le plus cher

Médiane `€/m²` des maisons E/F/G de Toulouse, par période :

| Période | E | F | G | n(G) |
|---|---|---|---|---|
| avant 1948 | 4 037 | 3 952 | **4 623** | 20 |
| 1948-1974 | 3 393 | 3 284 | **4 015** | 8 |
| récent | — | — | ≈ ∅ | 0 |

**Dans** le stratum avant-1948, G dépasse E et F, et **71 % des G sont pré-1948**
(20/28). Le DPE-G est un proxy de « vieux bâti de caractère désirable » (brique,
volumes, cachet) : le bien est à la fois le pire en énergie **et** le plus recherché.
L'indicatrice période capte le premium *moyen* du vieux, pas ce résidu *dans* le
vieux — il se loge donc dans le coefficient G.

Le rebond n'est pas qu'un artefact d'outliers : la distribution du G Toulouse (n=28)
a une médiane à 4 239 et un p75 à 5 628 — élevés vs F (médiane 3 699) — même si
5 ventes > 8 000 €/m² (max 232 663, donnée aberrante) gonflent la moyenne à 12 880.
L'écrêtage S32 n'attrape pas tout.

## 4. Décision

Ce qui est **déjà en place** et n'est donc pas un levier à réinventer : segmentation
maisons (SEGMENT_MVP), écartement des classes rares (`min_class_sample`, S21),
écrêtage d'outliers (S32 résidu), σ densité (S23), indice local (S24), sans effets
fixes commune (S22).

Le résidu #115 est **circonscrit** : l'extrême F/G des **maisons de cœur urbain
ancien dense**, tiré par le caractère du vieux bâti. Leviers réels :

- **Emplacement infra-communal** (effet fixe IRIS/quartier) — correction la plus
  directe du confondant ; fragmente l'échantillon.
- **Robustesse aux outliers** — la queue jusqu'à 232 663 €/m² passe partiellement
  l'écrêtage S32 ; à durcir sur ces mailles.
- **Neutraliser G** dans le discours produit sur ces mailles — pragmatique, et
  `min_class_sample` le fait déjà ailleurs de fait.
- **Ne pas forcer la monotonie** : masquerait un signal réel.

Apport neuf du pool 52 : la **preuve par le rural** que le modèle maisons est
monotone hors grandes villes. Le problème n'est pas systémique — argument pour
publier le rural et le cœur de gamme (E→C fiable partout) et ne neutraliser G que
là où l'emplacement le contamine.

**Suite** : cas de référence de non-régression (#56) sur deux jeux — le **52 rural**
comme cas positif (monotonie franche attendue) et les **maisons G de Toulouse**
comme cas-piège (non-monotonie assumée, non publiée).

## Requêtes

Lecture seule sur le dump du 13/08 restauré en dev. Segment maisons
(`type_local = 'Maison'`), **tous millésimes**, **sans bornes** (données brutes) ;
médiane par `percentile_cont(0.5)` (moyennes écartées : outliers). Coefficients pris
tels quels dans `coefficient_maille`. Zones par volume de ventes maisons de la
commune. Rebond : `(coef_dpe->>'G')::numeric > (coef_dpe->>'F')::numeric`, évalué
seulement là où les deux classes survivent à `min_class_sample`.
