# Résultat — S23 : σ adaptatif au rayon retenu (base S20+V2)

σ = rayon_retenu / diviseur en mode local. Sur `vv_lab_nobornes`, par-dessus la
base adoptée (S20 + S32-V2). `VV_MOTEUR_SIGMA_ADAPTATIF=true`,
`VV_MOTEUR_SIGMA_DIVISEUR=…`.

## Communes-test (toutes rayon 10 → σ uniforme)

| Commune | r² : réf → /3 → /2 | err % : réf → /3 → /2 | gain E→C : réf → /3 → /2 |
|---|---|---|---|
| Toulouse | 0,752 → **0,840** → 0,781 | 13,3 → 12,7 → 13,2 | +17,7 → +17,7 → +17,4 |
| Colomiers | 0,715 → 0,763 → 0,713 | 13,1 → 12,5 → 13,3 | +22,1 → +20,9 → +22,0 |
| Foix | 0,742 → 0,835 → 0,787 | 21,1 → 21,8 → **19,9** | +14,5 → +18,6 → +23,7 |
| Cazères | 0,630 → 0,722 → 0,662 | 26,3 → **30,4** → 23,9 | +25,6 → **−6,5 %** → +10,4 |

⚠️ **Diviseur 3 casse Cazères** : gain E→C **−6,5 %** (une classe C moins chère
qu'une E — aberrant). σ = 10/3 = 3,33 km est trop serré pour une maille fragile
(n=94, erreur 26 %) : trop peu de voisines effectives → coefficient instable.

## Vraie adaptativité — communes à grand rayon (65)

| Commune | rayon | r² : réf(σ=10) → /3 | gain E→C |
|---|---|---|---|
| 65160 | 15 | 0,714 → **0,824** (σ=5) | +27,2 → +24,0 % |
| 65481 | 20 | 0,812 → **0,851** (σ=6,67) | +25,6 → +29,9 % |

Ici l'adaptativité joue pleinement : une maille à rayon 15-20 reçoit un σ **plus
lâche** que les 3,33 km d'une maille dense, ce qui **fiabilise** l'ajustement
(65160 : r² 0,714 → 0,824) **sans** casser le signal (gains stables). C'est la
promesse de la piste B, confirmée.

## Verdict

**σ adaptatif : oui, mais diviseur 2, pas 3.**
- Diviseur 3 maximise le r² sur le dense et le rural à grand rayon, mais **casse
  les mailles fragiles à rayon 10** (Cazères, gain négatif). Inacceptable : ce
  chiffre irait dans un rapport client.
- **Diviseur 2** (σ = rayon/2) : gains nets et **sûrs** — Toulouse r² 0,752 →
  0,781, Foix passe **sous 20 %** d'erreur (19,9), Cazères reste positif (+10,4).

**Piste de raffinement** : diviseur 3 **plus un garde-fou** (σ plancher, ou σ
serré seulement si l'erreur/effectif le permet) récupérerait les gains du dense
sans casser le fragile. À explorer si l'on veut le meilleur des deux.

## Réserve technique

Un `overflow in exp` (regression.py, calcul du R²) apparaît sur échantillons
dégénérés : le R² fait un aller-retour `log(exp(·))` inutile et débordable. Sans
effet sur les résultats réels observés, mais à simplifier (tâche de suivi).
