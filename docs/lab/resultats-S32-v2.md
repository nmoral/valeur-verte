# Résultat — S32 V2 : écrêtage sur résidu pondéré (base sans bornes)

`VV_MOTEUR_RESTREINDRE_BATI_ANCIEN=false` + `VV_MOTEUR_ECRETAGE=residu` (k=3 MAD),
sur `vv_lab_nobornes`. Comparé à S20 (écrêtage off) et à V1 (prix/m²).

| Commune | config | n | r² | err % | m² bâti | m² terr | gain E→C |
|---|---|---|---|---|---|---|---|
| Toulouse | S20 | 1690 | 0,540 | 14,9 | 0,58 | 0,04 | +18,3 % |
| Toulouse | V1 | 1669 | 0,674 | 14,1 | 0,63 | 0,04 | +18,1 % |
| Toulouse | **V2** | 1607 | **0,752** | **13,3** | 0,59 | 0,04 | +17,7 % |
| Colomiers | S20 | 1282 | 0,549 | 14,0 | 0,57 | 0,03 | +20,3 % |
| Colomiers | V1 | 1268 | 0,611 | 13,8 | 0,62 | 0,03 | +18,1 % |
| Colomiers | **V2** | 1225 | **0,715** | **13,1** | 0,59 | 0,03 | +22,1 % |
| Cazères | S20 | 94 | 0,630 | 26,3 | 0,58 | 0,16 | +25,6 % |
| Cazères | V1 | 92 | 0,750 | 30,3 | 0,48 | 0,22 | +17,8 % |
| Cazères | **V2** | 94 | 0,630 | 26,3 | 0,58 | 0,16 | +25,6 % |
| Foix | S20 | 154 | 0,665 | 22,8 | 0,40 | 0,19 | +13,1 % |
| Foix | V1 | 152 | 0,688 | 22,3 | 0,46 | 0,19 | +13,8 % |
| Foix | **V2** | 150 | **0,742** | **21,1** | 0,32 | 0,29 | +14,5 % |
| Tarascon | toutes | — | REFUS (aucune classe DPE ≥ 15) | | | | |

## Lecture

1. **V2 > V1 sur le dense** : Toulouse r² 0,674 → **0,752**, Colomiers 0,611 →
   **0,715**. Cibler le résidu (bien mal expliqué vu ses caractéristiques) est plus
   efficace que le prix/m² brut.
2. **V2 aide le rural fragile, là où V1 échouait** : Foix passe de 22,8 % à
   **21,1 %** d'erreur (r² 0,665 → 0,742), alors que V1 ne l'améliorait pas. C'est
   exactement le rôle prévu pour V2.
3. **V2 ne casse pas ce qu'il ne peut pas aider** : Cazères (n=94 éligible < seuil
   100) n'est pas écrêté → inchangé. V1, lui, l'avait dégradé (erreur 26,3 → 30,3)
   car son gate porte sur l'échantillon brut, pas sur les seuls éligibles.
4. **Gains E→C stables** (±1-2 pt) : aucune distorsion du signal.
5. Retrait ~5 % de ventes (Toulouse 1690 → 1607) — un peu plus que V1, réglable
   via `k` (3,5 en retirerait moins).

## Verdict

**S32-V2 = le meilleur écrêtage, à retenir.** Il domine V1 sur toutes les mailles
testées : plus gros gain de r² sur le dense, améliore le rural fragile (Foix), et
laisse tranquilles les mailles trop petites pour être fiabilisées (Cazères).

## Note de cohérence (à trancher)

Le **gate** de V1 (échantillon brut prix/m²) et de V2 (éligibles) diffère : une
maille comme Cazères déclenche V1 mais pas V2. À aligner si l'on garde les deux ;
si V2 est retenu seul, la question tombe.
