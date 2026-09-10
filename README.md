# Resumerica

Static site with a small backend for two API routes: a Claude-powered ATS resume scan and a
consultation-booking form that emails you via Resend. Two interchangeable backends live in this
repo — pick one:

```
public/index.html        the website
public/blog.html         Insights page
public/api/              PHP backend — for Hostinger or any standard PHP+Apache host
src/worker.js            Cloudflare Worker backend — for Cloudflare's edge network
```

Both implement the same two routes (`/api/scan`, `/api/book`) with the same validation, prompt,
and email logic — use whichever matches where you're hosting. **Only deploy one at a time**: if
you're on Hostinger (PHP), you don't need `src/worker.js` or `wrangler.jsonc`; if you're on
Cloudflare, you don't need `public/api/`.

The API key lives only on the server. The browser never sees it, and the scan prompt is fixed
server-side so nobody can use your endpoint as a free general-purpose Claude proxy.

---

## Option A — Deploy to Hostinger (shared hosting)

### 1. Configure secrets

```bash
cp public/api/config.example.php public/api/config.php
```

Edit `public/api/config.php` and fill in:

| Constant | Value |
|---|---|
| `ANTHROPIC_API_KEY` | from console.anthropic.com → API Keys |
| `RESEND_API_KEY` | from resend.com → API Keys (optional — without it, bookings/leads only go to the PHP error log) |
| `NOTIFY_EMAIL` | the inbox that receives bookings + scan leads |
| `FROM_EMAIL` | *later*, once your domain is verified in Resend — e.g. `Resumerica <hello@yourdomain.com>` |
| `MODEL` | `claude-sonnet-5` (default) or `claude-haiku-4-5-20251001` for cheaper scans |

`config.php` is gitignored — it never gets committed or pushed. Save it as plain **UTF-8 without
BOM** (Notepad++, VS Code, or any code editor is fine; plain Windows Notepad can silently add a
BOM that breaks the API responses).

In the Anthropic Console, also set a **monthly spend limit** — your real safety net against
anyone hammering the scanner.

### 2. Upload

Deploy the contents of `public/` (not the repo root) to your domain's web root — usually
`public_html/`. Two ways:

- **hPanel → Git**: point it at this GitHub repo, and set the deploy directory to `public`.
- **File Manager / FTP**: upload everything inside `public/` (including the `api/` folder and
  your filled-in `config.php`) into `public_html/`.

Either way, confirm `public_html/api/config.php` exists on the server with your real keys in it —
it won't be in git, so a Git-based deploy needs it uploaded separately via File Manager/FTP once.

### 3. Make the `data/` folder writable

`public_html/api/data/` needs to be writable by PHP — it's used for lightweight per-IP rate
limiting. Via File Manager or FTP, set its permissions to `755` (or `775` if 755 doesn't work on
your setup). If PHP can't write to it, rate limiting just silently no-ops — it fails open, not
closed, so this step is optional hardening, not a requirement to launch.

### 4. Point your domain at Hostinger

If Hostinger is your registrar or you already use Hostinger's nameservers for this domain, this
is likely already done. Otherwise: hPanel → Domains → point the domain's nameservers or A record
at your Hostinger hosting.

### 5. Verify it's live

Visit your domain, run a free scan (try one of the sample resumes — no API key needed for those),
then submit a test booking. Check your `NOTIFY_EMAIL` inbox, and the PHP error log (hPanel →
Advanced → PHP Configuration, or your app's error log) if something doesn't show up.

---

## Option B — Deploy to Cloudflare Workers

### 1. Push to GitHub

```bash
git init && git add . && git commit -m "Initial deploy"
git branch -M main
git remote add origin https://github.com/<you>/resumerica.git
git push -u origin main
```

### 2. Put your domain on Cloudflare (free)

Cloudflare dashboard → **Add a domain** → point your registrar's nameservers at the two Cloudflare
gives you → wait until it shows **Active**.

### 3. Deploy the Worker

**Git (auto-deploys on every push):** Workers & Pages → Create → Import a repository → pick this
repo. Leave the build command empty; deploy command is `npx wrangler deploy`.

**CLI:**
```bash
npm install
npx wrangler login
npx wrangler deploy
```

### 4. Add secrets

Worker → Settings → Variables and Secrets → add each as type **Secret**:
`ANTHROPIC_API_KEY`, `RESEND_API_KEY`, `NOTIFY_EMAIL`, `FROM_EMAIL` (later).
CLI equivalent: `npx wrangler secret put ANTHROPIC_API_KEY`.

### 5. Connect the domain

Worker → Settings → Domains & Routes → Add → Custom domain → `yourdomain.com`, then
`www.yourdomain.com`.

### Local development

```bash
cp .dev.vars.example .dev.vars   # fill in keys
npm install
npm run dev                      # http://localhost:8787
```

---

## Email (Resend) — applies to both options

- **Before domain verification:** Resend only lets you send *to your own Resend account email*,
  so set `NOTIFY_EMAIL` to that address. You'll get every booking and every scan lead; hit reply
  to answer the customer directly.
- **After verifying your domain** in Resend: set `FROM_EMAIL`. Customers then also get a booking
  confirmation and an emailed copy of their scan report.

Without `RESEND_API_KEY`, bookings/leads are only logged (PHP error log, or Worker logs under
Observability) — fine for testing, not for launch.

## Hardening (recommended)

- **Rate limit the API** — Hostinger: the built-in per-IP limiter in `public/api/_helpers.php`
  covers basic abuse; for more, ask Hostinger support about WAF options on your plan. Cloudflare:
  your domain → Security → WAF → Rate limiting rules on `/api/*` (one rule free).
- **Cheaper scans:** set `MODEL` to `claude-haiku-4-5-20251001` (in `config.php` for Hostinger, or
  `wrangler.jsonc` for Cloudflare).
