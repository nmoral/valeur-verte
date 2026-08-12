# Résultat — S23 amélioré : σ piloté par la densité continue

Le rayon retenu est **quantifié** (10/15/20/30/50) et **plafonné à 10 km** :
Toulouse et une simple ville tombent toutes deux à « rayon 10 », alors que leurs
densités n'ont rien à voir — et Paris serait encore plus dense. Le rayon ne peut
donc pas régler σ finement.

**Mesure retenue** : `distance_min_sample` = distance **continue** à la
min_sample-ième (80ᵉ) vente la plus proche. Elle capte la vraie densité locale :

| Commune | d80 | lecture |
|---|---|---|
| Colomiers | 1,26 km | banlieue très dense |
| Toulouse | 1,79 km | dense |
| Foix | 2,44 km | petite ville |
| Cazères | 4,83 km | rural |
| 65160 | 11,4 km | montagne |
| 65481 | 15,3 km | montagne isolée |

Mode `densite` : σ = `distance_min_sample / diviseur`, borné par un plancher.

## ⚠️ Juger sur l'erreur CV, pas sur le r²

Un σ serré fait **exploser le r²** (Toulouse 0,752 → 0,931) mais c'est du
**sur-ajustement interne** : l'erreur médiane de validation croisée (hors
échantillon — celle qu'on publie) bouge à peine, voire empire. Toute la
calibration se fait donc sur l'**erreur CV**.

## Calibration du plancher (diviseur 1, erreur CV)

| Commune | fixe σ=10 | dens fl3 | dens fl4 | dens fl6 |
|---|---|---|---|---|
| Toulouse | 13,3 | **12,6** | 13,0 | 13,2 |
| Colomiers | 13,1 | **12,3** | 13,0 | 13,3 |
| Cazères | 26,3 | **24,3** | 24,3 | 25,1 |
| Foix | 21,1 | **20,3** | 20,6 | 20,7 |
| 65160 | 19,2 | **18,1** | 18,1 | 18,1 |
| 65481 | 18,7 | **18,2** | 18,2 | 18,2 |

**`densite`, diviseur 1, plancher 3 km améliore l'erreur CV sur les 6 communes**,
et aucun gain E→C ne devient aberrant (Cazères +9,1, Foix +8,5 — instables mais
positifs, cohérent avec leur fragilité déjà signalée par une erreur élevée).

## Verdict

**σ mode `densite`, diviseur 1, plancher 3 km — à retenir.** C'est la réponse au
problème « Toulouse dense mais rien à côté de Paris » : la densité continue règle
σ finement là où le rayon quantifié échouait, et le gain sur l'erreur CV est
**universel** (contrairement au mode `rayon`, qui cassait le fragile au diviseur 3).

Le mode `rayon` (S23 initial) reste disponible mais dominé.
