-- Schéma `data` : propriété du pipeline Python. L'application Symfony n'y accède
-- qu'en LECTURE SEULE (§10.2, décision D6).
CREATE SCHEMA IF NOT EXISTS data;

-- PostGIS : sélection spatiale des transactions pour le rayon adaptatif (§5.1).
CREATE EXTENSION IF NOT EXISTS postgis;
