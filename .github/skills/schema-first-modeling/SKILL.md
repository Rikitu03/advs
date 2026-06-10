---
name: schema-first-modeling
description: "ACTIVATE BEFORE any database model action — creating or editing an Eloquent model, writing or changing a migration, adding a relationship/cast/scope/accessor, building a factory or seeder, or writing an Eloquent query that depends on column names or types. Forces a schema-reconnaissance pass (read migrations + existing models + the live database with php artisan db:show / db:table / model:show / migrate:status) so model code matches the real columns, types, nullability, defaults, enums, indexes, foreign keys, and casts instead of being guessed. Trigger when the user mentions models, migrations, factories, seeders, relationships, casts, fillable/guarded, Eloquent, hasMany/belongsTo/hasOne/belongsToMany, schema, columns, foreign keys, or references app/Models/, database/migrations/, database/factories/, or names a table/model to create or modify. Do NOT skip this even for a 'quick' column or model tweak."
license: MIT
metadata:
  author: advs-team
---

# Schema-First Modeling

**Never write or change a model, migration, factory, seeder, relationship, or column-dependent query until you have grasped the real schema.** Guessing column names, types, nullability, or relationships is the most common source of silent bugs (wrong casts, broken `with()` eager loads, mass-assignment surprises, mismatched foreign keys). This skill makes schema reconnaissance a required first step, not an afterthought.

## Step 1 — Reconnaissance (always run before touching model code)

Do **all** of these for every table/model in scope. They are the canonical, always-available inspectors in this Laravel 12 app (verified present: `db:show`, `db:table`, `model:show`, `migrate:status`):

| Goal | Command / action |
|---|---|
| Which migrations exist and whether they've run | `php artisan migrate:status` |
| All tables, sizes, connection in use | `php artisan db:show` |
| **Exact columns of a table** — type, nullable, default, indexes, FKs | `php artisan db:table <table>` |
| A model's attributes, casts, relationships, observers as Laravel sees them | `php artisan model:show <Model>` |
| The *intended* schema (source of truth for new tables) | Read the migration files in `database/migrations/` |
| Existing conventions to mirror | Read sibling models in `app/Models/` and factories in `database/factories/` |

> If a table is only *planned* (a migration exists but `migrate:status` shows it pending, or it lives only in `CLAUDE.md` Phase 4), the **migration file is the source of truth** — `db:table` will fail because the table isn't there yet. Read the migration instead.

> Laravel Boost MCP tools (`database-schema`, `database-query`, `tinker`) may also be available — use them if present, but the `php artisan` commands above always work and need no MCP.

## Step 2 — Extract and write down what governs the model

Before writing code, confirm you know, for each table in scope:

- **Columns + types** — and the matching Eloquent cast (`integer`, `boolean`, `float`, `decimal:2`, `datetime`, `array`/`json`, enum class). A `json` column with no `array` cast returns a raw string and breaks silently.
- **Nullability + defaults** — drives `?type` hints, factory defaults, and whether a field is required in validation.
- **Enums** — an enum column constrains allowed values; mirror it with a PHP enum or a `const` set, never a free string.
- **Foreign keys + their `onDelete`** — drives `belongsTo`/`hasMany`/`hasOne` and cascade expectations.
- **Indexes + unique constraints** — a `unique` column needs the matching validation `Rule::unique(...)`.

## Step 3 — Model actions that follow from the schema

- **Casts go in the `casts()` method** (not the `$casts` property) — match this project's convention and Laravel 12 guidance. Every non-string column gets an explicit cast.
- **Define both sides of every relationship**, and verify the FK column name from Step 1 (don't rely on the Eloquent default if the migration named it differently).
- **`fillable` vs `guarded`** — list exactly the columns that exist; never `$guarded = []` on a table with sensitive/derived columns (e.g. `risk_score`, `status`, `role`).
- **Always create/extend a factory and seeder** for a new model, using real column constraints (respect enums, nullability, unique).
- **Modifying a column (Laravel 12 gotcha):** a `change()` migration must re-declare **all** previously-defined attributes on that column, or they are dropped. Read the original migration first (Step 1) so you don't silently lose `nullable()`, `default()`, or `unsigned()`.

## Step 4 — Verify

- After a migration: `php artisan migrate` then `php artisan db:table <table>` to confirm the real shape, and `php artisan model:show <Model>` to confirm casts/relationships resolve.
- Add or run the relevant test (`php artisan test --compact --filter=...`); use the model's factory in tests.
- Run `vendor/bin/pint --dirty --format agent` on changed PHP.

## ADVS context

> The ADVS data model is specified in two places — read them before creating the document-pipeline tables/models:
> - `CLAUDE.md` **Phase 4** lists the planned tables (`vendors`, `documents`, `validation_reports`, `signature_embeddings`, `stamp_feature_vectors`) with their exact columns, enums, and relationships.
> - [`ADVS_System_Reference.md`](../../../ADVS_System_Reference.md) **`§8`** (Database and Storage) governs *what* is stored and how — embeddings as **JSON** float vectors (cast to `array`), `risk_score` as float, append-only audit logs, and the configurable retention model. Cross-check `§9` for value ranges (risk bands, thresholds).
>
> When building those models, pair this skill with `laravel-best-practices` (for query/security patterns) and `advs-system-reference` (for domain meaning). Activate the `advs-system-reference` skill for the domain rules behind these columns.
