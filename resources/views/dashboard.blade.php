<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Merchant Dashboard</title>
    <style>
        :root {
            --ink: #1a2233;
            --muted: #64748b;
            --border: #e2e8f0;
            --bg: #f8fafc;
            --card: #ffffff;
            --blue: #2563eb;
            --orange: #d97706;
            --green: #16a34a;
            --red: #dc2626;
            --red-bg: #fef2f2;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--ink);
            padding: 24px;
        }
        .wrap { max-width: 1080px; margin: 0 auto; }
        header {
            background: #0f172a;
            color: #fff;
            padding: 16px 20px;
            border-radius: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }
        header h1 { font-size: 18px; margin: 0; font-weight: 600; }
        header .sub { font-size: 12px; color: #94a3b8; }
        .auth-bar {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 20px;
        }
        .auth-bar input {
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 13px;
            min-width: 120px;
        }
        .auth-bar input[name="api_key"] { flex: 1; min-width: 240px; font-family: monospace; }
        .auth-bar button {
            background: var(--blue);
            color: #fff;
            border: none;
            padding: 9px 16px;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            font-weight: 600;
        }
        .auth-bar button:hover { background: #1d4ed8; }
        .hint { font-size: 12px; color: var(--muted); width: 100%; }
        .error-banner {
            display: none;
            background: var(--red-bg);
            color: var(--red);
            border: 1px solid #fecaca;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
        }
        .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .stat {
            background: var(--card);
            border: 1px solid var(--border);
            border-left: 4px solid var(--blue);
            border-radius: 8px;
            padding: 14px 16px;
        }
        .stat.orange { border-left-color: var(--orange); }
        .stat.green { border-left-color: var(--green); }
        .stat .label { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
        .stat .value { font-size: 22px; font-weight: 700; margin-top: 4px; }
        .panels { display: grid; grid-template-columns: 1.3fr 1fr; gap: 16px; }
        @media (max-width: 800px) { .panels { grid-template-columns: 1fr; } }
        .panel {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
        }
        .panel h2 { font-size: 14px; margin: 0 0 12px; }
        .panel.risk { border-color: #fecaca; background: #fffafa; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; color: var(--muted); font-weight: 600; padding: 6px 8px; border-bottom: 1px solid var(--border); }
        td { padding: 8px; border-bottom: 1px solid #f1f5f9; }
        tr:last-child td { border-bottom: none; }
        .empty { color: var(--muted); font-size: 13px; padding: 8px; }
        .drop { color: var(--red); font-weight: 600; }
        .loading { text-align: center; padding: 40px; color: var(--muted); font-size: 13px; }
        .bar-track { background: #f1f5f9; border-radius: 4px; height: 6px; margin-top: 4px; overflow: hidden; }
        .bar-fill { background: var(--blue); height: 100%; }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div>
            <h1 id="merchant-title">Merchant Dashboard</h1>
            <div class="sub" id="cycle-label">&nbsp;</div>
        </div>
        <div class="sub">Live data from <code>GET /api/merchants/{id}/dashboard</code></div>
    </header>

    <div class="auth-bar">
        <input type="text" name="merchant_id" id="merchant_id" placeholder="Merchant ID" value="{{ $merchantId }}">
        <input type="text" name="api_key" id="api_key" placeholder="X-API-Key (paste the key printed by db:seed)">
        <button id="load-btn">Load dashboard</button>
        <div class="hint">The key is only kept in this browser's local storage - it is sent as the <code>X-API-Key</code> header on every request.</div>
    </div>

    <div class="error-banner" id="error-banner"></div>

    <div id="content">
        <div class="loading">Enter an API key and merchant ID above, then click "Load dashboard".</div>
    </div>
</div>

<script>
(function () {
    const els = {
        merchantId: document.getElementById('merchant_id'),
        apiKey: document.getElementById('api_key'),
        loadBtn: document.getElementById('load-btn'),
        content: document.getElementById('content'),
        error: document.getElementById('error-banner'),
        title: document.getElementById('merchant-title'),
        cycle: document.getElementById('cycle-label'),
    };

    const savedKey = localStorage.getItem('dashboard_api_key');
    if (savedKey) els.apiKey.value = savedKey;

    function money(cents) {
        return '₹' + (cents / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
    }

    function renderTopCustomers(rows) {
        if (!rows.length) return '<div class="empty">No usage recorded yet this month.</div>';
        return `<table>
            <thead><tr><th>Customer</th><th>Usage</th><th>% of allowance</th></tr></thead>
            <tbody>${rows.map(r => `
                <tr>
                    <td>${escapeHtml(r.customer_name)}</td>
                    <td>${r.units.toLocaleString()}</td>
                    <td style="min-width:120px">
                        ${r.percent_of_allowance !== null ? r.percent_of_allowance + '%' : '—'}
                        <div class="bar-track"><div class="bar-fill" style="width:${Math.min(100, r.percent_of_allowance ?? 0)}%"></div></div>
                    </td>
                </tr>`).join('')}
            </tbody>
        </table>`;
    }

    function renderChurnRisk(rows) {
        if (!rows.length) return '<div class="empty">No customers currently flagged as churn risk.</div>';
        return `<table>
            <thead><tr><th>Customer</th><th>Prev. month</th><th>This month (proj.)</th><th>Change</th></tr></thead>
            <tbody>${rows.map(r => `
                <tr>
                    <td>${escapeHtml(r.customer_name)}</td>
                    <td>${r.previous_month_units.toLocaleString()}</td>
                    <td>${(r.projected_month_units ?? r.current_month_units).toLocaleString()}</td>
                    <td class="drop">${r.percent_change}%</td>
                </tr>`).join('')}
            </tbody>
        </table>`;
    }

    function render(data) {
        els.title.textContent = 'Merchant Dashboard — #' + data.merchant_id;
        els.cycle.textContent = 'Current cycle: ' + data.cycle.start + ' to ' + data.cycle.end + ' · updated ' + new Date(data.generated_at).toLocaleString();

        els.content.innerHTML = `
            <div class="summary">
                <div class="stat">
                    <div class="label">Total usage this cycle</div>
                    <div class="value">${data.total_usage_this_month.toLocaleString()}</div>
                </div>
                <div class="stat orange">
                    <div class="label">Projected overage revenue</div>
                    <div class="value">${money(data.projected_overage_revenue_cents)}</div>
                </div>
                <div class="stat green">
                    <div class="label">Customers tracked</div>
                    <div class="value">${data.top_customers.length}</div>
                </div>
            </div>
            <div class="panels">
                <div class="panel">
                    <h2>Top 5 Customers by Usage (this cycle)</h2>
                    ${renderTopCustomers(data.top_customers)}
                </div>
                <div class="panel risk">
                    <h2>⚠ Churn Risk (usage ↓ &gt;50% MoM)</h2>
                    ${renderChurnRisk(data.churn_risk_customers)}
                </div>
            </div>
        `;
    }

    function showError(message) {
        els.error.textContent = message;
        els.error.style.display = 'block';
    }

    async function load() {
        const merchantId = els.merchantId.value.trim();
        const apiKey = els.apiKey.value.trim();
        els.error.style.display = 'none';

        if (!merchantId || !apiKey) {
            showError('Enter both a merchant ID and an API key.');
            return;
        }

        localStorage.setItem('dashboard_api_key', apiKey);
        els.content.innerHTML = '<div class="loading">Loading…</div>';

        try {
            const response = await fetch(`/api/merchants/${encodeURIComponent(merchantId)}/dashboard`, {
                headers: { 'X-API-Key': apiKey, 'Accept': 'application/json' },
            });

            if (!response.ok) {
                const body = await response.json().catch(() => ({}));
                showError(`Request failed (HTTP ${response.status}): ${body.message ?? 'Unknown error'}`);
                els.content.innerHTML = '';
                return;
            }

            render(await response.json());
        } catch (e) {
            showError('Network error: ' + e.message);
        }
    }

    els.loadBtn.addEventListener('click', load);
    if (savedKey && els.merchantId.value) load();
})();
</script>
</body>
</html>
