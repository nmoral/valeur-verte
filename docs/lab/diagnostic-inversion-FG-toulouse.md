# Diagnostic — l'inversion F/G de Toulouse est réelle (confondant âge/caractère)

Sous la config gagnante, Toulouse publie `F:-18% G:-12%` : G (pire classe énergie)
**moins décoté** que F, sur n=1441. Persiste à tous les seuils `min_class_sample`
→ pas du petit échantillon. Investigation sur les ventes réelles (rayon 10 km,
`vv_lab_nobornes`).

## L'inversion est dans la donnée brute

Prix/m² médian par classe DPE (maisons, 10 km autour du centre) :

| DPE | n | prix/m² | % avant 1948 |
|---|---|---|---|
| C | 569 | 3345 | 11 % |
| D | 490 | 3255 | 19 % |
| E | 304 | 3166 | 21 % |
| F | 100 | 3226 | 28 % |
| **G** | **43** | **3441** | **51 %** |

G se vend **plus cher au m²** que F (et que C/D/E). Ce n'est pas un artefact du
modèle : c'est réel.

## Le mécanisme : âge × caractère

Prix/m² médian E/F/G selon l'âge :

| Âge | E | F | G |
|---|---|---|---|
| 1948 et après | 3033 (n=241) | 3136 (n=72) | 3066 (n=21) |
| **avant 1948** | 3841 (n=63) | 3827 (n=28) | **4308 (n=22)** |

Deux effets s'empilent :
1. Les maisons **avant 1948** valent ~+25 % au m² que les modernes (premium
   « toulousaine de caractère »).
2. **Parmi les avant-1948, G est le plus cher** (4308 vs 3827 pour F) : les
   maisons les plus anciennes et authentiques (brique, volumes, cachet) sont **à la
   fois** les pires en énergie (→ G) **et** les plus désirables.
3. Et G contient **51 % d'avant-1948** (vs 28 % pour F) → sa moyenne est tirée vers
   le haut.

## Pourquoi le modèle ne l'absorbe pas

- La **période** est une indicatrice grossière (« avant 1948 » = un seul bucket) :
  elle capte le premium *moyen* du vieux, pas le fait que *dans* le vieux, G soit
  encore plus cher.
- L'**indice local** capte le niveau du *quartier*, pas le cachet *de cette
  maison-là*.
- Le résidu « vieux-G = premium de caractère » se loge donc dans le coefficient G.

## Conséquences produit

**Le DPE-G est un proxy de « vieux bâti de caractère désirable »**, pas seulement
de mauvaise énergie. Dans une ville ancienne dense (Toulouse, *a fortiori*
Paris/haussmannien), les pires passoires sont souvent les biens les plus recherchés.
C'est une **vraie limite de l'estimation de valeur verte sur données
observationnelles**, pas un bug.

- **Le gain E→C (+18,5 %) reste fiable** : cœur de distribution, peuplé, peu
  contaminé (E et C ne sont pas dominés par le pré-1948 de cachet comme l'est G).
- **Le coefficient G n'est pas exploitable** pour un discours « rénover = plus de
  valeur » : il mélange énergie et caractère.
- **Ne pas forcer la monotonie** : cela masquerait un signal réel et donnerait une
  fausse précision.

## Pistes de traitement (si on veut le résoudre)

- Croiser **DPE × période** (effet DPE séparé vieux/moderne) — fragmente l'échantillon.
- Période **plus fine**, ou un proxy de cachet.
- **Pragmatique (reco)** : publier le gain sur le **cœur de gamme** (E→C, D→B),
  marquer G comme hétérogène/non fiable, ne pas afficher d'écart impliquant G.
