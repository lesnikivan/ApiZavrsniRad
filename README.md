# Plant Care API

PHP/Slim API for the mobile plant-care app.

## Setup

1. Install Composer dependencies:

```bash
composer install
```

2. Copy environment variables:

```bash
cp .env.example .env
```

3. Create a PostgreSQL service in Railway and connect it to the backend service. Fill `.env` locally, or add the same variable in Railway:

- `GEMINI_API_KEY`: free-tier Google AI Studio key for Gemini 1.5 Flash.
- `DATABASE_URL`: PostgreSQL connection string provided by Railway.
- `DATABASE_TABLE`: optional table name, defaults to `plant_analyses`.
- `ADMIN_API_KEY`: private key required by administrator endpoints through the `X-Admin-Key` header.
- `TRELLO_KEY`, `TRELLO_TOKEN`, `TRELLO_LIST_ID`: optional Trello card creation.

4. Run the complete `database/schema.sql` in the Railway PostgreSQL query console. It creates the analysis and workflow tables and seeds the default workflow.

5. Start the API:

```bash
composer start
```

The API runs on `http://localhost:8000`.

## Endpoints

- `GET /health`: backend health check.
- `POST /analyze-plant`: receives `{ imageUri, imageBase64, mimeType }`, analyzes the plant, creates Trello cards if configured, and saves the analysis.
- `GET /plants`: returns saved analysis history.
- `GET /admin/services`: lists orchestration services. Requires `X-Admin-Key`.
- `PUT /admin/services/{serviceKey}`: enables or disables a service. Body: `{ "enabled": true }`.
- `GET /admin/workflows`: lists workflows and their ordered steps. Requires `X-Admin-Key`.
- `PUT /admin/workflows/{workflowId}/steps`: replaces the step order. Requires `X-Admin-Key`.
- `GET /admin/workflow-runs`: lists recent workflow runs. Requires `X-Admin-Key`.
- `GET /admin/workflow-runs/{runId}/logs`: returns detailed step logs. Requires `X-Admin-Key`.

The default workflow runs `ai_analysis`, `trello_cards`, and `save_analysis` in the order configured in PostgreSQL. Each run and each step is recorded in `workflow_runs` and `workflow_logs`.

If `GEMINI_API_KEY` is empty, the API returns demo plant data so the mobile app can be developed without paid services.
