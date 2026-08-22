# Backlog — index des issues

Généré à partir du document de cadrage MVP, complété par
[Onboarding self-service](ONBOARDING-SELF-SERVICE.md),
[Tests d'invariants et qualité des données](QUALITE-DONNEES.md) et
[Page publique hébergée](PAGE-PUBLIQUE.md). 14 epics, 81 tâches/décisions.
Les grandeurs statistiques citées ici sont définies dans le
[glossaire](GLOSSAIRE-STATISTIQUE.md).
Les numéros renvoient aux [issues](../../../issues) du dépôt.

> Statut initial de toutes les issues : `statut: à faire`. Voir le [README](../README.md)
> pour la convention de labels et les filtres.

## Feuille de route (jalons §11)

| Jalon | Epic principal | Critère de sortie |
|---|---|---|
| **J1 — Données** | [#1](../../../issues/1) | Base chargée, appariement DVF/DPE > 60 %, coefficients sur ≥ 80 % des communes |
| **J2 — Moteur** | [#2](../../../issues/2) | Coefficients cohérents sur 5 zones test vérifiées |
| **J3 — Mode audit** | [#3](../../../issues/3) [#4](../../../issues/4) | Un pro produit un rapport complet en RDV, sans assistance |
| **J4 — Admin pro** | [#5](../../../issues/5) [#104](../../../issues/104) | Un pro s'inscrit, produit un rapport et configure son compte seul, dans cet ordre |
| **J5 — Pilote** | [#64](../../../issues/64) | ≥ 10 rapports/pro sur 2 mois + retour qualitatif |
| **J6 — Page publique** | [#6](../../../issues/6) | Parcours de bout en bout sur la page hébergée d'un pilote (D9) |

## Epics et sous-tâches

### [#1](../../../issues/1) Données & ingestion — `jalon: J1`
- [#13](../../../issues/13) Schéma PostgreSQL `data` (PostGIS) + contrat lecture seule
- [#14](../../../issues/14) Ingestion DVF géolocalisé (Etalab)
- [#15](../../../issues/15) Appariement DPE (ADEME) : étiquette + période de construction
- [#16](../../../issues/16) Géocodage BAN + référentiel Découpage Administratif
- [#17](../../../issues/17) Nettoyage des transactions + gestion du millésime COG
- [#18](../../../issues/18) Couverture géographique en base + règle des limitrophes + log hors-zone
- [#19](../../../issues/19) Chargement initial Occitanie + rafraîchissement semestriel

### [#2](../../../issues/2) Moteur statistique — `jalon: J2`
- [#20](../../../issues/20) Sélection d'échantillon par rayon adaptatif
- [#21](../../../issues/21) Régression linéaire pondérée (bâti ancien, gaussienne, effets fixes)
- [#22](../../../issues/22) Mode dégradé sous n=30 + seuil plancher
- [#23](../../../issues/23) Validation croisée + erreur médiane par maille
- [#24](../../../issues/24) Pré-calcul par maille + table `coefficient_maille` + batch parallèle

### [#3](../../../issues/3) Calcul économique & barèmes — `jalon: J3`
- [#25](../../../issues/25) Chaîne de calcul économique
- [#26](../../../issues/26) Barèmes d'aides versionnés (sans UPDATE destructif)
- [#27](../../../issues/27) Mise en avant de l'effet de seuil C

### [#4](../../../issues/4) Mode audit (prioritaire) — `jalon: J3`
- [#28](../../../issues/28) Saisie pro + validation des bornes
- [#29](../../../issues/29) Sélection du bouquet de travaux
- [#30](../../../issues/30) Ligne d'ajustement du professionnel (motivée, jamais surcharge)
- [#31](../../../issues/31) Restitution (n, IC, rayon, millésime) + indicateur de fiabilité
- [#32](../../../issues/32) Génération du PDF à l'en-tête du pro
- [#33](../../../issues/33) Instantané de rapport immuable (JSONB)

### [#5](../../../issues/5) Espace pro / back-office — `jalon: J4`
- [#34](../../../issues/34) **ONB-3** Configuration des coûts par poste (défauts pré-remplis)
- [#35](../../../issues/35) Bouquets de travaux types (CRUD) — bouquet par défaut actif
- [#36](../../../issues/36) Branding + prévisualisation du PDF en direct
- [#37](../../../issues/37) Liste unique rapports & leads
- [#38](../../../issues/38) Écran pro : configurer et partager sa page publique (slug, lien, activation) — [D9](PAGE-PUBLIQUE.md)
- [#39](../../../issues/39) **ONB-4** Premier rapport avant configuration

### [#6](../../../issues/6) Page publique hébergée — `jalon: J6`

Le widget iframe embarqué chez le pro est **abandonné** au profit d'une page que nous
hébergeons : décision **D9**, [#266](../../../issues/266). Le dispositif tel qu'il tourne
— routage, anti-abus, CSP, double opt-in, mesure — est décrit dans
[PAGE-PUBLIQUE.md](PAGE-PUBLIQUE.md), qui fait autorité.

- [#266](../../../issues/266) **D9** La page publique hébergée remplace le widget iframe (abandon de D1)
- [#40](../../../issues/40) Page hébergée `widget.<domaine>/{token}/{slug}` + rendu marque blanche
- [#41](../../../issues/41) Parcours particulier : adresse → premier chiffre → mur de contact
- [#42](../../../issues/42) Capture de lead + double opt-in (mail pro, back-office, RGPD)
- [#43](../../../issues/43) Anti-abus préventif : plafonds multi-dimensions + Turnstile + honeypot
- [#44](../../../issues/44) Rate limiting (token/IP) + notification admin
- [#267](../../../issues/267) Branding hébergé : anti-XSS/SSRF de l'URL de logo + CSP de la zone
- [#268](../../../issues/268) Anti-abus réactif : détection de pics, alerte, kill switch
- [#90](../../../issues/90) Tracking anonyme du parcours (RGPD) + purge
- [#287](../../../issues/287) Autocomplétion d'adresse BAN (relais serveur) → code INSEE

### [#7](../../../issues/7) Admin interne & exploitation — `jalon: transverse`
- [#45](../../../issues/45) Supervision des coefficients + flag valeurs aberrantes
- [#46](../../../issues/46) Monitoring d'ingestion
- [#47](../../../issues/47) Gestion des comptes (essai, compteur, parrainages)
- [#48](../../../issues/48) Page publique de statut du modèle
- [#49](../../../issues/49) Canal de retour « ce chiffre me semble faux »
- [#50](../../../issues/50) Journal d'audit + socle EasyAdmin

### [#8](../../../issues/8) Architecture, infra & sécurité — `jalon: transverse`
- [#51](../../../issues/51) Monorepo + découpage en deux projets
- [#52](../../../issues/52) Séparation des schémas `data`/`app` + migrations
- [#53](../../../issues/53) Monolithe Symfony à 3 firewalls + login par rôle
- [#54](../../../issues/54) Multi-tenant en base unique (filtre Doctrine)
- [#55](../../../issues/55) Sauvegarde/restauration testée + archivage des PDF
- [#56](../../../issues/56) **REG-3** Environnement de recette + cas de référence (fourchettes)

### [#9](../../../issues/9) Juridique, RGPD & conformité — `jalon: transverse`
- [#57](../../../issues/57) Cadrage juridique avec un avocat
- [#58](../../../issues/58) RGPD : sous-traitance art.28 + traduction fonctionnelle
- [#59](../../../issues/59) Réutilisation des données publiques (mention de source)
- [#60](../../../issues/60) Facturation : outil externe raccordé à une Plateforme Agréée

### [#10](../../../issues/10) Produit, tarification & commercial — `jalon: transverse`
- [#61](../../../issues/61) Tarification + logique d'essai + bilan d'usage
- [#62](../../../issues/62) Parrainage en mois offerts (plafond)
- [#63](../../../issues/63) ⚠️ Vérifier la clause d'exclusivité employeur avant de facturer
- [#64](../../../issues/64) Pilote : 2 pros en gratuit (critère J5)

### [#11](../../../issues/11) Décisions ouvertes — `type: décision`
- [#65](../../../issues/65) Seuil d'essai (10 rapports) à calibrer
- [#66](../../../issues/66) Afficher le seuil de neutralité dans le PDF ?
- [#67](../../../issues/67) Publier la méthodologie ou la garder opaque ?
- [#68](../../../issues/68) Durée de purge des leads (défaut 24 mois)

### [#104](../../../issues/104) Onboarding self-service — `domaine: onboarding`

Voir [ONBOARDING-SELF-SERVICE.md](ONBOARDING-SELF-SERVICE.md). ONB-3 et ONB-4 sont portées par des
issues existantes de l'epic [#5](../../../issues/5).

- [#105](../../../issues/105) **ONB-1** Inscription ouverte — `jalon: J4`
- [#106](../../../issues/106) **ONB-2** Remplissage automatique par le SIRET — `jalon: J4`
- [#34](../../../issues/34) **ONB-3** Grilles de coûts pré-remplies (+ [#35](../../../issues/35)) — `jalon: J4`
- [#39](../../../issues/39) **ONB-4** Premier rapport avant configuration — `jalon: J4`
- [#107](../../../issues/107) **ONB-5** Paiement autonome — `jalon: transverse`
- [#108](../../../issues/108) **ONB-6** Compteur d'essai visible — `jalon: transverse`
- [#109](../../../issues/109) **ONB-7** Relance conversationnelle après le premier rapport — `jalon: transverse`
- [#110](../../../issues/110) **ONB-8** Support porté par l'interface — `jalon: transverse`
- [#111](../../../issues/111) Métriques d'activation à instrumenter — `jalon: transverse`

### [#118](../../../issues/118) Tests d'invariants & qualité des données — `domaine: qualité-données`

Voir [QUALITE-DONNEES.md](QUALITE-DONNEES.md). Traiter [#123](../../../issues/123) (INV-5) en
premier : sans batch déterministe, REG-1 et REG-3 sont inexploitables.

**Assertions d'ingestion** — fin d'ingestion, avant calcul — `jalon: J1`
- [#124](../../../issues/124) **DAT-1** Schéma des sources ingérées
- [#125](../../../issues/125) **DAT-2** Volumétrie par département (± 30 %)
- [#126](../../../issues/126) **DAT-3** Appariement DVF ↔ DPE (≥ 60 %)
- [#127](../../../issues/127) **DAT-4** Distributions (prix, surfaces, étiquettes)
- [#128](../../../issues/128) **DAT-5** Fraîcheur des sources

**Invariants du modèle** — fin de calcul, par maille — `jalon: J2`
- [#119](../../../issues/119) **INV-1** Monotonie de l'échelle DPE
- [#120](../../../issues/120) **INV-2** Signe et ordre de grandeur
- [#121](../../../issues/121) **INV-3** Cohérence géographique
- [#122](../../../issues/122) **INV-4** Qualité du modèle comme critère de publication
- [#123](../../../issues/123) **INV-5** Reproductibilité du batch

**Non-régression** — avant publication — `jalon: J2`
- [#129](../../../issues/129) **REG-1** Rapport d'écarts entre millésimes
- [#130](../../../issues/130) **REG-2** Publication manuelle d'un millésime
- [#56](../../../issues/56) **REG-3** Cas de référence en fourchettes

**Journalisation** — `jalon: transverse`
- [#131](../../../issues/131) Rapport de batch archivé

### [#12](../../../issues/12) v2 / Backlog — `jalon: v2`
- [#69](../../../issues/69) Mode locatif (interdiction de location comme moteur)
- [#70](../../../issues/70) Piste copropriété (syndics, DPE collectif)
- [#71](../../../issues/71) Extension géographique (Nouvelle-Aquitaine + AURA)
- [#72](../../../issues/72) Pistes moteur : pôle urbain, variantes de rayon, segmentation
