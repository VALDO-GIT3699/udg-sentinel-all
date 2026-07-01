# Speckit Constitution - UDG Sentinel

Version: 1.0.0  
Effective Date: 2026-06-30

## Purpose
This constitution defines non-negotiable engineering principles for UDG Sentinel. All architecture decisions, pull requests, and technical specs must align with these principles.

## Principle 1: Laravel 12 Modular Architecture First
- The backend MUST remain aligned with Laravel 12 conventions and lifecycle.
- Domain functionality MUST be implemented in isolated modules under `backend/Modules/`.
- Cross-module coupling MUST happen through contracts, events, or explicit service interfaces, never through hidden direct dependencies.
- Controllers MUST stay thin and delegate orchestration to dedicated application services.
- Configuration, providers, and bootstrapping MUST be explicit and environment-safe.

Acceptance checks:
- New features are placed in the appropriate module and respect module boundaries.
- No business rules are embedded directly in routes or controllers.
- Service container bindings are explicit for non-trivial dependencies.

## Principle 2: Repository Pattern as Data Access Boundary
- Data access MUST be encapsulated behind repository interfaces (`Contracts` + concrete repositories).
- Application services MUST depend on repository contracts, not on raw query builders or model internals.
- Repositories MUST expose intention-revealing methods (for example, `findActiveByAssetId`) instead of generic catch-all methods.
- Transactions MUST be controlled at service/use-case level when workflows touch multiple aggregates.
- Caching decisions MUST be explicit, testable, and invalidation-aware.

Acceptance checks:
- New use cases include repository contract usage, implementation, and tests.
- No direct DB query logic appears in controllers, middleware, or Inertia page components.
- Repository methods include query scopes/index assumptions for performance-critical paths.

## Principle 3: Strict TypeScript for Frontend Reliability
- TypeScript strict mode MUST remain enabled and enforced in CI.
- `any` is forbidden unless justified with a documented exception and follow-up refactor ticket.
- Shared domain types MUST be centralized and reused across pages/components.
- API and Inertia props MUST be fully typed with discriminated unions where state variants exist.
- Runtime validation MUST be used at external boundaries when payload trust is low.

Acceptance checks:
- `tsconfig` strict settings remain active.
- Pull requests introducing `any`, unchecked casts, or implicit `unknown` downcasts are rejected unless justified.
- New pages/components include typed props, emits, and composables.

## Principle 4: Inertia.js + Vue 3 Dashboard Rendering Discipline
- Dashboard pages MUST use Inertia responses as the source of truth for server-driven state.
- Heavy dashboard computations MUST occur server-side; Vue components focus on rendering and interaction.
- Each dashboard view MUST define a stable typed page-props contract and avoid ad hoc payload shapes.
- Navigation, filters, sorting, and pagination MUST preserve UX state intentionally.
- Rendering performance MUST be protected through lazy loading, code splitting, and selective partial reloads where appropriate.

Acceptance checks:
- Inertia page contracts are explicitly typed and versioned when changed.
- Large dashboard payloads are paginated or sliced; no unbounded data transfers.
- Expensive client re-renders are prevented by proper component boundaries and memoization patterns.

## Principle 5: MySQL Performance and Operational Efficiency
- Schema design MUST be index-driven and query-pattern aware.
- Every high-frequency WHERE/JOIN/ORDER BY path MUST have validated index coverage.
- Migrations MUST define constraints, index names, and charset/collation intentionally.
- N+1 query patterns are prohibited in critical flows; eager loading strategy MUST be explicit.
- Query latency budgets MUST be defined for dashboard-critical endpoints.

Acceptance checks:
- EXPLAIN plans are reviewed for new or changed critical queries.
- Composite indexes are added when filtering patterns require them.
- Slow query logs and production telemetry are used to guide optimization priorities.

## Engineering Guardrails
- Any exception to this constitution MUST be documented in an ADR under `docs/adr/`.
- Temporary exceptions MUST include expiration criteria and an owner.
- Code review MUST validate constitutional compliance before approval.
- CI SHOULD include static analysis, test checks, and lint gates aligned with these principles.

## Amendment Process
1. Propose a change through an ADR referencing affected principle(s).
2. Obtain approval from technical leadership.
3. Update this constitution with semantic versioning:
   - MAJOR: principle removal or incompatible redefinition.
   - MINOR: new principle or materially expanded requirement.
   - PATCH: clarifications without changing intent.

## Compliance Checklist (PR Template Reference)
- Laravel 12 architecture boundaries respected.
- Repository Pattern contracts and implementations aligned.
- TypeScript strict typing preserved.
- Inertia/Vue dashboard rendering contract validated.
- MySQL indexing and query performance considered.