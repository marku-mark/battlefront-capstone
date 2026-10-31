---
paths:
    - 'app/Http/Controllers/**,app/Actions/**,app/Services/**,app/Repositories/**'
---

# Application Architecture

## Controllers

Keep controllers as thin HTTP/Inertia boundaries: handle requests, retrieve validated input, authorize, call an Action or Service, and return responses, redirects, and flash messages. Prefer standard resource methods (`index`, `create`, `store`, `show`, `edit`, `update`, `destroy`) when they describe the endpoint, but keep explicit endpoints for genuinely distinct operations. Do not keep substantial business rules, transaction orchestration, long queries, or reusable transformations in controllers.

## Actions

Use an Action for one focused business operation or use case, exposed consistently through `__invoke()`, `handle()`, or `execute()`. Stock adjustment, product-image replacement, and payment-proof resubmission are Action-sized concerns. Do not add a Service around a small focused Action.

## Services

Use a Service when a feature coordinates multiple related rules or operations, especially across a transaction, several models, repositories, or Actions. Services own workflow orchestration; do not create empty or pass-through Services.

## Repositories

Use a repository only when persistence or query complexity gains a clear boundary. Repositories own queries and persistence, not business decisions. Keep simple Eloquent CRUD and model scopes direct instead of wrapping them for abstraction alone.

## Repository Contracts and Interfaces

Prefer concrete repositories. Add a contract only when multiple implementations are realistically required, strict substitution is valuable, or an infrastructure boundary genuinely benefits from replacement. Do not create an interface for every repository.

## Base Repository Policy

Do not create a generic `BaseRepository` by default. Introduce an abstract base only after multiple concrete repositories demonstrate meaningful repeated query or persistence behavior; never use one merely to mirror Eloquent CRUD.

## General Decision Rules

Choose the simplest design with clear responsibility boundaries. Do not automatically create Repository + Interface + Service layers for each model or feature. Extract based on demonstrated workflow, query, reuse, or testability needs while preserving existing Laravel and Eloquent conventions.

## Example Dependency Flows

Simple focused operation: `Controller -> Action`.

Coordinated workflow: `Controller -> Service -> Repository` and/or `Service -> Actions`.

## Database Query Performance

- Avoid N+1 queries by eager-loading only necessary relationships.
- Select only required database columns when practical.
- Use pagination for potentially large datasets instead of loading all records.
- Prefer database-level filtering, sorting, counting, and aggregation over processing large collections in PHP.
- Reuse existing Eloquent scopes and queries before adding abstractions.
- Use indexes for frequently filtered, joined, or sorted columns when justified by actual query performance.
- Use EXPLAIN and query measurements to identify bottlenecks before optimizing.
- Keep simple queries in controllers when they only prepare responses. Move complex or reusable queries into an existing Service or a Repository when justified.
- Preserve existing business rules, authorization, transactions, and data integrity.
- Avoid premature optimization, unnecessary repositories, and speculative database changes.
