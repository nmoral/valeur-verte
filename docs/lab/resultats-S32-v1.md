# Résultat — S32 V1 : écrêtage sur prix/m² (sur base S20)

`VV_MOTEUR_RESTREINDRE_BATI_ANCIEN=false` (S20 adopté) + `VV_MOTEUR_ECRETAGE=prix_m2`
(fraction 1 %, seuil n ≥ 100). Millésime 2024. Comparé à la référence S20
(écrêtage off).

| Commune | n (S20 → S32) | r² (S20 → S32) | err % (S20 → S32) | gain E→C (S20 → S32) |
|---|---|---|---|---|
| Toulouse (urbain) | 1577 → 1553 | 0,548 → **0,635** | 13,8 → **13,1** | +14,2 % → +14,2 % |
| Colomiers (périurbain) | 1223 → 1203 | 0,521 → **0,588** | 13,7 → **13,2** | +17,5 % → +16,0 % |
| Cazères (rural 31) | 71 → 71 | 0,558 → 0,558 | 24,8 → 24,8 | n/a¹ |
| Foix (rural 09) | 133 → 131 | 0,595 → 0,594 | 23,7 → 24,2 | +9,8 % → +10,3 % |
| Tarascon (petit n) | REFUS → REFUS | — | — | — |

¹ Cazères : C ou E reste < 15 ventes → gain E→C indisponible.

## Lecture

1. **Gros gain de r² sur les mailles denses** pour ~1,5 % de ventes retirées :
   Toulouse 0,548 → **0,635** (+0,087), Colomiers 0,521 → **0,588** (+0,067).
   Confirme l'hypothèse de l'audit : quelques comparables au prix/m² extrême, à
   fort levier, dégradaient l'ajustement.
2. **Erreur médiane en baisse** sur urbain/périurbain (13,8→13,1 ; 13,7→13,2).
3. **Gains E→C stables** (±1,5 pt) : l'écrêtage **ne distord pas le signal**.
4. **Le gate protège les petits échantillons** : Cazères (n=71 < 100) n'est pas
   écrêté — inchangé. Comportement voulu.
5. **Sans effet sur le rural fragile** : Foix (n=131 ≥ 100) est écrêté mais r² et
   erreur ne bougent quasiment pas (24,2 %). Ses outliers ne sont pas le problème
   — la maille est intrinsèquement bruitée/rare. Piste pour **V2 (résidu
   pondéré)**, qui cible l'inadéquation conditionnelle plutôt que le prix/m² brut.

## Verdict

**S32 V1 = adopter sur les mailles denses.** Bénéfice net (r² ↑, erreur ↓, signal
préservé, perte de données minime), aucun effet pervers (gate correct). Reste à
tester **V2** pour voir si elle aide là où V1 ne fait rien (Foix, Cazères).
