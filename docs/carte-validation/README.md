# Carte de validation de la base — France

Choroplèthe interactive (HTML autonome, SVG + JS inline, **sans dépendance réseau**) qui
valide la base de coefficients DPE d'un millésime, **par département** : granularité de la
cascade (LOCAL / EPCI / DÉPARTEMENTAL), fiabilité (tier dégradé), premium E→C, couverture.
Survol d'un département → détail ; panneau « régions à surveiller ».

Maquette fonctionnelle pour l'intégration au site (#224). Source des données =
`data.coefficient_maille_historique` — le millésime **calculé et archivé, avant publication
REG-2** (#130). Pour une intégration, afficher le millésime **publié**, pas le dernier calculé.

## Fichiers

| Fichier | Rôle | Versionné |
|---|---|---|
| `generer.py` | Générateur : projette les contours en SVG + agrège les métriques + assemble le HTML | oui |
| `template.html` | Gabarit HTML (placeholder `__DATA__`) | oui |
| `dept-contours.geojson` | Contours départementaux (géométrie ~statique) | oui |
| `validation-france-2025.html` | Rendu de référence (millésime 2025) | oui |
| `mailles-<millesime>.csv`, `communes-par-dept.csv` | Exports DB éphémères | non (regénérés) |

## Régénérer

### 1. Exporter les données depuis le schéma `data` (sur le VPS)

```sh
DB="docker exec valeur-verte-db-1 psql -U valeur_verte -d valeur_verte"

# Contours départementaux = union des communes (à refaire seulement si commune_contour change)
$DB -At -f - <<'SQL' > dept-contours.geojson
SELECT json_build_object('type','FeatureCollection','features', json_agg(feat))
FROM (
  SELECT json_build_object(
    'type','Feature',
    'properties', json_build_object('dept', code_departement),
    'geometry', ST_AsGeoJSON(ST_SimplifyPreserveTopology(ST_Union(geom::geometry), 0.008))::json
  ) AS feat
  FROM data.commune_contour GROUP BY code_departement
) sub;
SQL

# Mailles archivées du millésime
$DB -c "\copy (SELECT code_insee, mode, n_transactions, erreur_mediane, palier, alerte, coef_dpe::text, effectifs_dpe::text FROM data.coefficient_maille_historique WHERE millesime=2025 ORDER BY code_insee) TO STDOUT WITH CSV" > mailles-2025.csv

# Nombre de communes par département (pour la couverture)
$DB -At -F',' -c "SELECT left(code_insee,2), count(*) FROM data.commune_contour GROUP BY 1 ORDER BY 1" > communes-par-dept.csv
```

### 2. Générer le HTML

```sh
python generer.py --millesime 2025 --data-dir .
# -> validation-france-2025.html
```

### 3. Rafraîchir les contours servis par le site (#224)

La page publique `/valeur-verte-par-departement` **ne rejoue pas ce générateur** : elle lit les
métriques du millésime publié en base et n'a besoin que de la **géométrie**, servie depuis
`app/resources/publication/contours-departements.json`. Cet asset ne se régénère que si
`data.commune_contour` change (fusion de communes, nouveau découpage) :

```sh
python - <<'PY'
import json
from pathlib import Path
from generer import projeter_contours

feats = projeter_contours(Path("dept-contours.geojson"))
Path("../../app/resources/publication/contours-departements.json").write_text(
    json.dumps({f["dept"]: f["path"] for f in feats}, ensure_ascii=False, separators=(",", ":"))
)
PY
```

Les tests `app/tests/Publication/ContoursDepartementsTest.php` vérifient l'asset (96 départements,
chemins fermés, boîte 760×720) : un export tronqué ou reprojeté fait rougir la suite.

## Notes

- **Colonnes du CSV `mailles`** (ordre attendu par `generer.py`) : `code_insee, mode,
  n_transactions, erreur_mediane, palier, alerte, coef_dpe, effectifs_dpe`.
- **Gain E→C** = `exp(coef_C − coef_E) − 1` (les coefficients sont des effets **log**).
- **Alsace-Moselle (57/67/68)** est hors périmètre DVF (livre foncier) → absente de la carte.
- La projection est équirectangulaire corrigée en longitude (métropole), boîte 760×720 alignée
  sur le `viewBox` du gabarit.
