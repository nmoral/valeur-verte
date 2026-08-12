# Résultat — Baseline (config par défaut)

Millésime 2024. Config = défauts historiques (restriction < 1989 **active**).
Pools : 31 (+limitrophes) = 19 601 maisons · 09 (+limitrophes) = 12 205.

| Commune | n | mode | rayon | r² | err % | m² bâti | m² terrain | gain E→C |
|---|---|---|---|---|---|---|---|---|
| Toulouse (urbain) | 1162 | local | 10 | 0,527 | 13,8 | 0,55 | 0,04 | **+14,6 %** |
| Colomiers (périurbain) | 760 | local | 10 | 0,477 | 14,7 | 0,57 | 0,02 | **+18,8 %** |
| Cazères (rural 31) | — | **REFUS** | — | — | — | — | — | — |
| Foix (rural 09) | 103 | local | 10 | 0,602 | 22,8 | 0,51 | 0,15 | **+8,2 %** |
| Tarascon (petit n) | — | **REFUS** | — | — | — | — | — | — |

## Cause des refus (diagnostic)

- **Cazères** : n_local éligible = 50, n_dept éligible = 2680, mode = local, mais
  **seule la classe D atteint 15 ventes** (< 2 classes publiables → refus).
- **Tarascon** : n_local éligible = 37, n_dept éligible = 512, mode = local, mais
  **aucune classe** n'atteint 15 → refus.

## Observations

1. Les refus ne viennent **pas du volume brut** (37–50 ventes locales) mais de la
   **rareté par classe DPE** (S21), causée par l'empilement restriction < 1989
   (S20) + taux d'appariement DPE. → S20 devrait densifier les classes.
2. **Foix dépasse déjà la barre INV-4** (22,8 % > 20 %) : maille rurale fragile.
3. Gains E→C plausibles et positifs (+8 à +19 %). Terrain plus élevé en rural
   (0,15) qu'en urbain (0,02–0,04), cohérent. Tous les coef terrain sont positifs
   sur cet échantillon.
