# Contribuer à Valeur verte

Merci de l'intérêt que tu portes au projet. Les contributions sont les bienvenues —
corrections, tests, documentation, améliorations du moteur de calcul ou de l'application.

Ce document décrit **comment contribuer**, **sous quelle licence** tes contributions sont
versées, et **comment les contributeurs participent aux décisions** du projet, notamment
celles relatives à l'usage commercial par un tiers.

## 1. Avant de commencer

- Les **règles de travail techniques** (cycle TDD Red → Green → Refactor, un worktree et
  une branche par issue, portes qualité `make check` / `make mutation`, conventions de
  commit) font autorité et sont décrites dans [`CLAUDE.md`](CLAUDE.md). Toute contribution
  doit les respecter.
- Toute contribution part d'une **issue** : voir le [backlog](docs/BACKLOG.md). Si ton
  idée n'a pas d'issue, ouvre-en une pour en discuter avant d'écrire du code.
- Ouvre ensuite une **Pull Request vers `main`**, focalisée sur une seule issue, avec
  `Closes #<num>` dans le corps.

## 2. Licence de tes contributions

Le projet est publié sous **[PolyForm Noncommercial 1.0.0](LICENSE)** (usage non
commercial libre ; usage commercial soumis à une licence distincte accordée par écrit).

En soumettant une contribution (Pull Request, patch, ou tout autre apport), tu déclares et
acceptes que :

1. **ta contribution est versée sous cette même licence** PolyForm Noncommercial 1.0.0,
   dans les mêmes conditions que le reste du projet ;
2. tu disposes des **droits nécessaires** pour la soumettre (c'est ton travail original,
   ou tu es autorisé à le contribuer) ;
3. tu accordes à **Nicolas Moral** (le créateur et titulaire des droits sur le projet) une
   licence **perpétuelle, mondiale, irrévocable et transférable** l'autorisant à
   **relicencier ta contribution sous d'autres conditions, y compris des licences
   commerciales** accordées à des tiers.

Ce point (3) est **indispensable** : sans lui, le créateur ne pourrait pas accorder de
licence commerciale couvrant l'ensemble du projet, puisqu'une partie du code
appartiendrait à ses contributeurs. Il **ne te retire aucun droit** : tu restes titulaire
du copyright de ta contribution et libre d'en faire ce que tu veux par ailleurs.

## 3. Gouvernance et décisions d'usage commercial

- **Les contributeurs reconnus sont consultés** sur les décisions relatives à l'usage
  commercial du projet par un tiers (octroi d'une licence commerciale, partenariat,
  conditions, etc.). Leur avis est sollicité et compte dans la discussion.
- **La décision finale appartient au créateur.** L'utilisation du projet — et en
  particulier toute autorisation d'usage commercial — reste **à l'entière discrétion de
  Nicolas Moral**, titulaire des droits. La consultation des contributeurs est un
  **processus consultatif**, non un droit de veto ni un pouvoir de décision.

### Qui est « contributeur reconnu » ?

Est **contributeur reconnu** toute personne dont **au moins une contribution significative
a été fusionnée dans `main`** (code, tests, ou documentation substantielle), ou toute
personne à qui le créateur attribue explicitement ce statut. Le créateur tient la liste
des contributeurs reconnus et peut la préciser au cas par cas.

## 4. Demande d'usage commercial

Toute demande d'usage commercial (y compris de la part d'un contributeur) passe par une
**issue** intitulée « Demande de licence commerciale » sur
<https://github.com/nmoral/valeur-verte/issues>. Aucune autorisation commerciale n'est
réputée accordée sans **accord écrit explicite** du créateur.

---

En contribuant, tu acceptes les termes de ce document ainsi que la licence du projet.
