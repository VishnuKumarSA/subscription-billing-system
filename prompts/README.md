# AI Prompt Log

This project was built with Claude (Anthropic) as a pair-programmer inside VS Code. Screenshots of the actual prompts (captured from the Claude VS Code chat panel) belong in this folder alongside this file, one per major stage below.

## Development stages

1. **Requirements analysis.** Given the full assignment brief (PDF), asked Claude to act as a Senior/Staff Laravel engineer and produce a requirement breakdown, assumptions, architecture proposal, domain entities, workflows, risks, implementation order, and a list of questions to be ready to explain in interview — explicitly *before* writing any code. Purpose: force the ambiguous parts of the brief (usage granularity, proration formula, allowance proration on plan change, "projected overage" methodology) to be surfaced and decided deliberately, not discovered mid-implementation.

2. **Backend architecture design.** Asked for a full design pass covering domain model, database tables/relationships, API boundaries, service/action classes, DTOs, queue jobs, events/listeners, caching, rate limiting, idempotency, the billing calculation flow, the plan-change flow, and dashboard aggregation — with an explicit instruction to prefer simple Laravel-native solutions and not over-engineer, and to justify each decision against the 3-day timebox. Purpose: settle the shape of the system once, so implementation could proceed without re-deciding architecture mid-build.

3. **Production database schema.** A follow-up specifically on the logical schema — exact columns/types/keys/indexes/composite indexes, query patterns, 50M+ scaling reasoning, future partitioning strategy, and what should/shouldn't be denormalized yet. Purpose: get the highest-risk, hardest-to-change layer (the schema) fully reasoned through before any migration was written.

4. **Full implementation.** A single directive to build the complete working application in the workspace (not just describe it): migrations, models, DTOs, pure billing calculators, actions, queue jobs, scheduled commands, controllers/requests/resources/policies, the API-key auth guard, caching, rate limiting, the dashboard service + UI, seeders, and the automated test suite — run and passing, not just written. Purpose: get to a demonstrable, testable system.

5. **Verification and bug-fixing (this stage, done in-line rather than as a separate prompt).** Ran the full test suite repeatedly while building, caught and fixed a `DATE`-column comparison bug (SQLite vs. MySQL storage-format difference) that the test suite alone didn't surface, caught and fixed a churn-risk methodology bug via manual reasoning about seeded demo data (a raw partial-month-vs-full-month comparison mechanically flags nearly everyone mid-month), and caught a 500-vs-401 bug on unauthenticated requests via manual `curl` smoke-testing that the test suite's `postJson()` helper had been masking (it sends `Accept: application/json` automatically; a plain `curl` doesn't). All three are called out explicitly in the README rather than silently fixed, since how they were found is part of demonstrating the review process.

## Note on the screenshots

Per the brief's instructions, the actual screenshots were captured by the developer from their own Claude VS Code panel and are not fabricated or embedded here by the assistant.
