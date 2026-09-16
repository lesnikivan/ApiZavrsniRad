create table if not exists plant_analyses (
  id text primary key,
  plant_name text not null,
  scientific_name text,
  confidence numeric not null default 0,
  image_uri text,
  summary text not null,
  tasks jsonb not null default '[]'::jsonb,
  created_at timestamptz not null default now()
);
