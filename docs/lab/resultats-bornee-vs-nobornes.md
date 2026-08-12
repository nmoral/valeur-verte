# Résultat — config gagnante sur base bornée vs sans bornes

Même config gagnante (S20 + V2 + σ densité + sans EF commune + indice + `min_class_sample=20`),
appliquée à l'identique sur les deux bases pour isoler l'effet des **données**
(bornes + fraîcheur) : `vv_lab` (bornée, dump du 11/08) vs `vv_lab_nobornes`
(ré-ingérée sans bornes).

| Commune | BORNÉE (vv_lab) | SANS BORNES |
|---|---|---|
| Toulouse | n=1345 err=**11,0** gain=+17,7 6cl | n=1441 err=12,3 gain=+18,5 6cl |
| Colomiers | n=1115 err=**11,7** gain=+20,4 6cl | n=1165 err=12,4 gain=+20,8 **7cl** |
| Cazères | **REFUS** | n=94 err=20,9 gain=+16,8 3cl |
| Foix | n=120 err=19,8 gain=+5,6 | n=142 err=**16,8** gain=+7,4 |
| 65160 | n=138 err=20,5 gain=+27,6 | n=162 err=**18,3** gain=+32,7 |
| 65481 | n=81 err=20,4 | n=93 err=21,3 |

## ⚠️ Le piège : sur le dense, la base bornée affiche une erreur PLUS BASSE

Toulouse 11,0 vs 12,3, Colomiers 11,7 vs 12,4. Au premier regard la base bornée
« prédit mieux ». **C'est un artefact de censure.** Les bornes retirent les biens
extrêmes → la population restante est **artificiellement homogène** → plus facile à
prédire → erreur plus basse. Le modèle répond alors à une question **tronquée**
(le milieu de gamme), pas au marché réel.

**Les deux erreurs ne sont pas comparables** : elles sont mesurées sur des
**populations différentes** (l'une censurée, l'autre complète). L'erreur plus haute
du sans-bornes vient de ce qu'il prédit le **marché entier** — c'est la mesure
honnête, pas une infériorité.

## Là où le sans-bornes gagne franchement

- **Couverture** : Cazères calcule (bornée = REFUS) ; Colomiers publie 7 classes vs 6.
- **Rural** : Foix **16,8 vs 19,8**, 65160 **18,3 vs 20,5** — les données en plus
  fiabilisent les mailles auparavant affamées.
- **Gain E→C non biaisé** : toujours un peu plus haut sans bornes (la censure du
  haut de gamme sous-estimait la valeur verte) — Foix +7,4 vs +5,6.

## Conclusion

La base bornée paraît meilleure sur le dense **seulement parce qu'elle s'est
facilité la tâche en jetant les cas difficiles**. Sur le fond, sans bornes = plus
de couverture, meilleur sur le rural, gain non biaisé, erreur honnête. **Le choix
S12 tient.** Et cette comparaison rappelle qu'on **ne peut pas comparer l'erreur CV
de deux périmètres de données différents** : c'est un critère intra-périmètre.
