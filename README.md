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
- `TRELLO_KEY`, `TRELLO_TOKEN`, `TRELLO_LIST_ID`: optional Trello card creation.

4. Run `database/schema.sql` in the Railway PostgreSQL query console to create the `plant_analyses` table.

5. Start the API:

```bash
composer start
```

The API runs on `http://localhost:8000`.

## Endpoints

- `GET /health`: backend health check.
- `POST /analyze-plant`: receives `{ imageUri, imageBase64, mimeType }`, analyzes the plant, creates Trello cards if configured, and saves the analysis.
- `GET /plants`: returns saved analysis history.

If `GEMINI_API_KEY` is empty, the API returns demo plant data so the mobile app can be developed without paid services.
