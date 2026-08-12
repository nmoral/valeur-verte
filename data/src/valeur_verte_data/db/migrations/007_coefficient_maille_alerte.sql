-- Bandeau non masquable de la maille (#162) : alerte de bord (INV-3 #3, #121) ou
-- mode dégradé départemental (#22). Calculé au batch, il doit être **servable** —
-- donc stocké avec le coefficient, pas seulement journalisé. Nullable : la
-- plupart des mailles n'ont aucune alerte.
ALTER TABLE data.coefficient_maille ADD COLUMN IF NOT EXISTS alerte TEXT;
