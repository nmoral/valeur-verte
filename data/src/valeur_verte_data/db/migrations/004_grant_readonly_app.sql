-- Contrat A/B (§10.2, décision D6) : l'application Symfony accède au schéma `data`
-- en LECTURE SEULE. Le pipeline Python peut être réécrit sans toucher à l'app.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'valeur_verte_app') THEN
        CREATE ROLE valeur_verte_app NOLOGIN;
    END IF;
END
$$;

GRANT USAGE ON SCHEMA data TO valeur_verte_app;
GRANT SELECT ON ALL TABLES IN SCHEMA data TO valeur_verte_app;

-- Les tables futures du schéma `data` seront aussi en SELECT seul pour l'app.
ALTER DEFAULT PRIVILEGES IN SCHEMA data GRANT SELECT ON TABLES TO valeur_verte_app;
