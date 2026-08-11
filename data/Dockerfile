# Image du pipeline « data » déployée sur le VPS (#78).
#
# Elle embarque tout le paquet : elle applique aujourd'hui les migrations, et
# accueillera sans changement les CLI `ingest` / `compute` par département (#91)
# en surchargeant simplement la commande.
FROM python:3.11-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /opt/valeur-verte

# Le paquet est installé sans son extra « dev » : ni pytest ni ruff en production.
COPY pyproject.toml ./
COPY src ./src
RUN pip install --no-cache-dir . \
    && useradd --create-home --uid 10001 pipeline

USER pipeline

# `python -m <module>` : la commande du compose ne fournit que le module à jouer.
ENTRYPOINT ["python", "-m"]
CMD ["valeur_verte_data.db.migrate"]
