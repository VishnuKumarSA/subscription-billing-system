# Manual Testing Guide (Postman)

A step-by-step walkthrough of the API, matching `postman_collection.json` folder-by-folder. Import that file into Postman and run the requests **in order, top to bottom** — later requests depend on earlier ones (e.g. the plan-change step needs the subscription created two steps before it).

## Setup

1. Start the app: `php artisan serve` (from the project root, after `php artisan migrate --seed`).
2. In Postman: **Import** → select `postman_collection.json`.
3. The collection already has working variables filled in (`base_url`, `acme_key`, `globex_key`, and every seeded plan/customer/subscription/invoice ID) — they match the data currently in `database/database.sqlite`. If you run `php artisan migrate:fresh --seed` again, the database resets and these API keys stop working; see **"If you reset the database"** at the bottom.
4. No further setup needed — every request already has its `X-API-Key` header set from the collection variables.

## What the seeded data represents

The seeder built two merchants so you can exercise every scenario in the brief without creating data by hand:

| Merchant | Customer | What it demonstrates |
|---|---|---|
| Acme Corp (#1) | Beta Retail, Craft Foods, Delta Mart | Steady usage, top-5 dashboard candidates |
| Acme Corp (#1) | Nova Traders, QuickMart | >50% churn-risk drop (matches the brief's wireframe) |
| Acme Corp (#1) | **Orbit Solutions** (#6) | On Starter plan, usage past the included allowance → **overage billing** |
| Acme Corp (#1) | **Helix Systems** (#7) | **Mid-cycle plan change** (Starter → Growth) with two pricing segments |
| Globex Inc (#2) | Initech LLC | A second tenant, for proving cross-tenant isolation |

## Folder 0 — Health & Auth Checks

Confirms the app is up and that authentication is enforced before anything else. `GET /up` → 200. The two auth checks (`No API key`, `Invalid API key`) should both return **401** — this is what proves the API can't be used without a valid, real key.

## Folder 1 — Plans

- **List Acme's plans** → 200, 3 plans (Starter, Growth, Pro).
- **Show Growth plan** → 200, shows `base_price_cents: 300000`, `included_units: 250000`.
- **Create a new plan** → 201. Standard plan creation.
- **Update Growth plan pricing** → 200, price changes to `310000`. This is the cache-invalidation path: internally, updating a plan clears its Redis/cache entry immediately rather than waiting for the 10-minute TTL — you won't see this directly in Postman, but it's why the *next* time anyone reads or subscribes to this plan, they get the new price instantly, not a stale one.
- **Cross-tenant: Globex key views Acme's plan** → **403**. This is the tenant-isolation check — proves a merchant's key can never read another merchant's resources, even by guessing a valid numeric ID.

## Folder 2 — Customers

- **List Acme's customers** → 200, 7 customers.
- **Create a test customer** → 201. This captures the new customer's ID into `test_customer_id`, used by the rest of the flow (subscriptions, usage).
- **Show Beta Retail** → 200.
- **Cross-tenant: Acme key views Globex's customer** → **403**.

## Folder 3 — Subscriptions & Plan Changes

- **Show Helix Systems' subscription** → 200, and its `segments` array has **2 entries**: one for Starter (closed, `ends_on` set) and one for Growth (open, `ends_on: null`). This is the mid-cycle plan change requirement made visible — usage recorded before the change stays attributed to the Starter segment, usage after to the Growth segment.
- **Create a subscription for the test customer** (Starter plan) → 201. Captures `test_subscription_id`.
- **Duplicate active subscription for the same customer** → **422**. The system refuses to let one customer have two active subscriptions at once (that would double-bill them) — the error is on `customer_id`.
- **Upgrade the test subscription to Growth** → 200. `current_plan.name` is now `"Growth"`, and `segments` now has 2 entries — this is the live plan-change flow you just triggered, not seeded data.
- **Cross-tenant: Globex key changes Acme's subscription plan** → **403**.

## Folder 4 — Usage Events

- **Record usage for the test customer** → **201**. Captures the event ID.
- **Retry the SAME request** (identical `idempotency_key`) → still **201**, but the response's `id` is **the same** as the first call. This is the core idempotency guarantee: check `database/database.sqlite`'s `usage_events` table (or just trust the test assertion) — there is only **one row**, not two, even though you sent the request twice.
- **Unknown customer_id** → 422.
- **Cross-tenant customer_id** (Globex's customer, sent with Acme's key) → 422 — proves usage can't be attributed to another merchant's customer even if you know their ID.
- **Missing units** → 422, standard validation.

### Testing the rate limit (optional, not in the collection by default)

The limit is 120 requests/minute per API key. To see a `429`, use Postman's **Collection Runner** (or a simple loop) to fire the "Record usage" request 121+ times in under a minute with a unique `idempotency_key` each time (e.g. append `{{$timestamp}}` to the key in the body) — the 121st response should be `429 Too Many Requests`. This isn't wired into the default run because it would spam 120+ rows into your demo data every time you run the collection.

## Folder 5 — Dashboard

- **Acme dashboard** → 200. Look at `churn_risk_customers` — you should see **Nova Traders** and **QuickMart**, matching the brief's own wireframe example, and *not* Beta Retail/Craft Foods/Delta Mart even though they have less usage this month than last (their drop is a normal mid-month artifact, correctly not flagged — see README §17 for why). `projected_overage_revenue_cents` and `top_customers` are also populated from real seeded usage.
- **Globex dashboard** → 200, a much smaller dataset (one customer).
- **Cross-tenant: Acme key views Globex's dashboard** → **403**.

## Folder 6 — Invoices (the auditable breakdown)

- **List Orbit Solutions' invoices** → 200, one invoice from last month's completed cycle.
- **Show Orbit's invoice detail** → 200. This is the full audit trail the brief asks for: `total_cents: 149350` (₹1,493.50), and the line item shows `usage_units`, `included_units_snapshot`, `overage_units`, `overage_rate_micros_snapshot`, and `overage_amount_cents` — every number that fed the calculation, not just the final total. Orbit is on Starter (10,000 included, ₹0.10/unit overage); the numbers should satisfy `overage_units = usage_units - included_units_snapshot` and `overage_amount_cents = round(overage_units × overage_rate_micros_snapshot / 10000)`.
- **Cross-tenant: Globex key views Acme's invoice** → **403**.

## If you reset the database

`php artisan migrate:fresh --seed` wipes all data and prints two **new** API keys to the console (a key's plaintext only ever exists at creation time, so old ones can never be recovered) — the ones baked into this collection will stop working. The seeder's daily-usage variance is seeded (`mt_srand(42)`), so re-seeding **on the same calendar day** reproduces byte-for-byte identical customer IDs, plan IDs, subscription IDs, and invoice totals — only the API keys change. Re-seeding on a **different day** keeps every scenario structurally the same (same customers flagged as churn risk/overage/mid-cycle-change) but shifts the exact totals slightly, since "this month" and "last month" now cover different day counts.

Either way: grab the freshly printed keys and paste them into the collection's `acme_key`/`globex_key` variables — the numeric IDs (1–8) will still match this guide on the same day, and the *shape* of every scenario still matches even across days.
