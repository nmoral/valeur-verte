# Résultat — S12 : base ré-ingérée SANS bornes (re-validation)

Base `vv_lab_nobornes` : 7 départements ré-ingérés sur le VPS **sans aucune borne
de nettoyage** (millésime 2024), puis rapatriée. **42 731 ventes** contre 28 579
en 2024 borné → **+49 %**. La reprise 429 (déployée) a absorbé ~50-130
rate-limits par département sans perdre une seule commune.

> Rappel : le nettoyage (S12) s'applique à l'**ingestion**, pas au calcul. Cette
> base contient donc toutes les ventes ; les runs ci-dessous n'y appliquent aucune
> borne. Réserve méthodo : données ré-ingérées **fraîches**, donc l'écart avec
> `vv_lab` mêle « effet des bornes » et « fraîcheur » (mineur sur 2024 clos).

## Les trois variantes sur la base sans bornes

| Commune | config | n | r² | err % | m² bâti | m² terr | gain E→C |
|---|---|---|---|---|---|---|---|
| Toulouse | baseline | 1244 | 0,540 | 14,6 | 0,57 | 0,05 | +18,9 % |
| Toulouse | S20 | 1690 | 0,540 | 14,9 | 0,58 | 0,04 | +18,3 % |
| Toulouse | S20+S32 | 1669 | **0,674** | 14,1 | 0,63 | 0,04 | +18,1 % |
| Colomiers | baseline | 797 | 0,501 | 16,1 | 0,55 | 0,03 | +21,3 % |
| Colomiers | S20 | 1282 | 0,549 | 14,0 | 0,57 | 0,03 | +20,3 % |
| Colomiers | S20+S32 | 1268 | **0,611** | 13,8 | 0,62 | 0,03 | +18,1 % |
| Cazères | baseline | 70 | 0,824 | 35,4 | 0,64 | 0,18 | n/a |
| Cazères | S20 | 94 | 0,630 | 26,3 | 0,58 | 0,16 | +25,6 % |
| Cazères | S20+S32 | 92 | 0,750 | 30,3 | 0,48 | 0,22 | +17,8 % |
| Foix | baseline | 122 | 0,625 | 22,1 | 0,39 | 0,20 | +14,1 % |
| Foix | S20 | 154 | 0,665 | 22,8 | 0,40 | 0,19 | +13,1 % |
| Foix | S20+S32 | 152 | 0,688 | 22,3 | 0,46 | 0,19 | +13,8 % |
| Tarascon | toutes | — | REFUS (aucune classe DPE ≥ 15) | | | | |

## Le constat majeur : les bornes biaisaient le gain E→C

À **config identique** (baseline), retirer les bornes déplace le gain E→C
**vers le haut de +3 à +6 points** :

| Commune | gain E→C borné (`vv_lab`) | gain E→C sans bornes |
|---|---|---|
| Toulouse | +14,6 % | **+18,9 %** |
| Colomiers | +18,8 % | **+21,3 %** |
| Foix | +8,2 % | **+14,1 %** |

Le nettoyage fixe (plafond bâti 250 m², prix 900 k) **n'était pas neutre** : en
coupant le haut de gamme, il **sous-estimait la valeur verte**, le chiffre phare
du produit. C'est l'argument le plus fort en faveur de S12.

Autre effet : **Cazères, refusée en base bornée, calcule dès le baseline sans
bornes** (n=70) — retirer les bornes densifie aussi les classes DPE.

## Verdicts (confirmés sur données complètes)

- **S12 (retirer/relâcher les bornes) : adopter.** Non seulement +49 % de ventes
  et plus de couverture, mais surtout **correction d'un biais de plusieurs points
  sur le gain E→C**.
- **S20 : confirmé.** Plus de données, r² égal/meilleur, débloque des communes,
  gains stables.
- **S32-V1 : confirmé sur les mailles denses** — bond de r² (Toulouse
  0,540→0,674, Colomiers 0,549→0,611). **Contre-productif sur le rural fragile**
  (Cazères : erreur 26,3→30,3, gain qui saute 25,6→17,8) → réserver V1 au dense,
  V2 (résidu) pour le rural.
- **Tarascon reste bloqué** quelle que soit la config : c'est **DAT-3**
  (appariement DPE trop faible sur le 09), indépendant des bornes/S20/S32.
