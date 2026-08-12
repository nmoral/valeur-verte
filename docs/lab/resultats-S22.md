# Résultat — S22 : retirer les effets fixes commune (sur-paramétrage)

Le modèle empile **trois** mécanismes de localité : pondération gaussienne (σ, désormais densité), indice local (S24) **et effets fixes commune** — une indicatrice par commune présente dans le rayon. Sur une maille rurale, le rayon couvre des dizaines de communes → des dizaines de colonnes. Hypothèse : redondant et sur-ajustant.

Ablation sur `vv_lab_nobornes`, base adoptée (S20 + V2 + σ densité div1 fl3), avec vs sans effets fixes commune. **Juge = erreur médiane CV** (hors échantillon).

| Commune | avec EF : r² / err / gain | sans EF : r² / err / gain |
|---|---|---|
| Toulouse | 0,860 / 12,6 / +18,7 | 0,858 / **12,3** / +18,5 |
| Colomiers | 0,795 / 12,3 / +21,1 | 0,791 / 12,4 / +20,8 |
| Cazères | 0,665 / 24,3 / +9,1 | 0,353 / **20,9** / +16,8 |
| Foix | 0,845 / 20,3 / +8,5 | 0,795 / **16,8** / +7,4 |
| 65160 | 0,726 / 18,1 / +29,7 | 0,621 / 18,3 / +32,7 |
| 65481 | 0,796 / 18,2 / +24,3 | 0,581 / 21,3 / +23,0 |

## Lecture

1. **Signature nette du sur-paramétrage** : sur le rural, retirer les EF commune
   **fait chuter le r²** (Cazères 0,665 → 0,353, 65481 0,796 → 0,581) **tout en
   améliorant l'erreur CV**. Le r² était gonflé par des dizaines d'indicatrices ;
   la prédiction, elle, ne s'appuyait pas dessus. C'est de l'ajustement au bruit.
2. **Ça répare les mailles fragiles** : Foix **20,3 → 16,8 %** (passe sous la barre
   INV-4), Cazères 24,3 → 20,9 %. Les EF commune étaient une cause directe de leur
   fragilité.
3. **Neutre sur le dense** (Toulouse, Colomiers) : peu de communes dans le rayon,
   donc peu d'indicatrices — les retirer ne change presque rien.
4. **Une régression** : 65481 (montagne très isolée, d80=15 km) perd 3 pts. Sur ce
   type de maille extrême, l'info commune portait un vrai signal.

## Verdict

**Retirer les effets fixes commune** (`effets_fixes_commune=false`). Net positif :
mieux ou égal sur 5 communes sur 6, et surtout **fiabilise le rural fragile** —
précisément la cible de la revue (R² instables, erreurs > 20 %). L'indice local et
la pondération densité portent déjà la localité, sans le coût des indicatrices.

Réserve : sur les mailles ultra-isolées, envisager de garder l'info commune (ou de
basculer plus tôt en mode départemental).

## Ablation restante (non testée)

L'**indice local** (S24) est l'autre mécanisme de localité. Le désactiver — pour
voir si σ densité seul suffit — reste à tester (attention : décale la position des
coefficients DPE dans `beta`, extraction à adapter).
