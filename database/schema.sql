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

create table if not exists orchestration_services (
  id text primary key,
  service_key text not null unique,
  display_name text not null,
  enabled boolean not null default true,
  created_at timestamptz not null default now()
);

create table if not exists workflows (
  id text primary key,
  name text not null,
  enabled boolean not null default true,
  created_at timestamptz not null default now()
);

create table if not exists workflow_steps (
  id bigserial primary key,
  workflow_id text not null references workflows(id) on delete cascade,
  service_key text not null references orchestration_services(service_key),
  step_order integer not null,
  configuration jsonb not null default '{}'::jsonb,
  enabled boolean not null default true,
  unique (workflow_id, step_order)
);

create table if not exists workflow_runs (
  id text primary key,
  workflow_id text,
  status text not null check (status in ('pending', 'running', 'completed', 'failed')),
  started_at timestamptz not null default now(),
  finished_at timestamptz,
  error_message text
);

create table if not exists workflow_logs (
  id bigserial primary key,
  run_id text not null references workflow_runs(id) on delete cascade,
  service_key text not null,
  step_order integer not null,
  status text not null check (status in ('running', 'completed', 'failed')),
  message text,
  started_at timestamptz not null default now(),
  finished_at timestamptz
);

insert into orchestration_services (id, service_key, display_name)
values
  ('service-ai', 'ai_analysis', 'AI analiza biljke'),
  ('service-trello', 'trello_cards', 'Trello kartice'),
  ('service-database', 'save_analysis', 'Spremanje analize')
on conflict (service_key) do nothing;

insert into workflows (id, name)
values ('plant-analysis-default', 'Analiza biljke')
on conflict (id) do nothing;

insert into workflow_steps (workflow_id, service_key, step_order)
values
  ('plant-analysis-default', 'ai_analysis', 1),
  ('plant-analysis-default', 'trello_cards', 2),
  ('plant-analysis-default', 'save_analysis', 3)
on conflict (workflow_id, step_order) do nothing;
