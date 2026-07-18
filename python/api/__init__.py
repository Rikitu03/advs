"""ADVS ML API — FastAPI wrapper around the python/ validation pipeline.

Stateless HTTP service for independent deployment (Hugging Face Docker Space
or any container host). Laravel remains the orchestrator and single owner of
state: vendor signature references and issuer logo vectors live in Laravel's
DB and are passed in requests; the composite risk score (Stage 5) is computed
by Laravel's RiskScoreService, never here.
"""

__version__ = "0.1.0"
