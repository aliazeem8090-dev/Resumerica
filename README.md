# Resumerica

Static site with a small backend for two API routes: a Claude-powered ATS resume scan and a
consultation-booking form that emails you via Resend.

```
index.html                 the website
blog.html                  Insights page
api/                       PHP backend (primary) — scan.php, book.php, config.php
cloudflare-worker/         Cloudflare Worker backend — alternate deploy target, not used by default
```

**The repo root is the deployable web root** — that's what makes Hostinger's Git deploy work
without any extra steps (see below). `cloudflare-worker/` is kept only as a reference/fallback if
you ever want to run this on Cloudflare instead; it's blocked from public access by `.htaccess`
and isn't needed for the Hostinger deploy.

The API key lives only on the server. The browser never sees it, and the scan prompt is fixed
server-side so nobody can use your endpoint as a free general-purpose Claude proxy.

---

## Deploy to Hostinger (shared hosting)

### 1. Configure secrets

```bash
cp api/config.example.php api/config.php
```

Edit `api/config.php` and fill in:

| Constant | Value |
|---|---|
| `ANTHROPIC_API_KEY` | from console.anthropic.com → API Keys |
| `RESEND_API_KEY` | from resend.com → API Keys (optional — without it, bookings/leads only go to the PHP error log) |
| `NOTIFY_EMAIL` | the inbox that receives bookings + scan leads |
| `FROM_EMAIL` | *later*, once your domain is verified in Resend — e.g. `Resumerica <hello@yourdomain.com>` |
| `MODEL` | `claude-sonnet-5` (default) or `claude-haiku-4-5-20251001` for cheaper scans |

`config.php` is gitignored — it never gets committed or pushed, so it has to be uploaded/created
separately from the Git deploy (step 2 explains). Save it as plain **UTF-8 without BOM**
(Notepad++, VS Code, or any code editor is fine; plain Windows Notepad can silently add a BOM that
breaks the API responses).

In the Anthropic Console, also set a **monthly spend limit** — your real safety net against
anyone hammering the scanner.

### 2. Deploy via hPanel → Git

1. hPanel → your website → **Advanced → Git** (or **Git** under Websites, depending on your
   hPanel layout).
2. **Create a new repository** (or "Add repository"):
   - Repository URL: `https://github.com/aliazeem8090-dev/Resumerica.git`
   - Branch: `main`
   - **Directory**: leave this blank / set to `public_html` — the repo root has to land directly
     in `public_html`, not a subfolder, since `index.html` and `api/` now live at the repo root.
3. Hostinger requires the target directory to be **empty** on the first deploy. If `public_html`
   already has a default Hostinger placeholder page in it, delete those files first (File
   Manager), then deploy.
4. Click **Deploy**. Every future `git push` to `main` can be redeployed the same way (either a
   manual "Deploy" click in hPanel, or a webhook if your plan/version of the Git feature offers
   one — check the panel for a "Deploy on push" or webhook URL option).
5. **`config.php` will not come from Git** (it's gitignored on purpose — real keys never touch the
   repo). After the first deploy, go to File Manager → `public_html/api/` and either:
   - upload your locally-filled `config.php` via File Manager's upload button, or
   - create `config.php` directly in File Manager's code editor and paste in the contents of
     `config.example.php` with your real values filled in.

### 3. Make the `data/` folder writable

`public_html/api/data/` needs to be writable by PHP — it's used for lightweight per-IP rate
limiting. Via File Manager, set its permissions to `755` (or `775` if 755 doesn't work on your
setup). If PHP can't write to it, rate limiting just silently no-ops — it fails open, not closed,
so this step is optional hardening, not a requirement to launch.

### 4. Point your domain at Hostinger

If Hostinger is your registrar or you already use Hostinger's nameservers for this domain, this is
likely already done. Otherwise: hPanel → Domains → point the domain's nameservers or A record at
your Hostinger hosting.

### 5. Verify it's live

Visit your domain, run a free scan (try one of the sample resumes — no API key needed for those),
then submit a test booking. Check your `NOTIFY_EMAIL` inbox, and the PHP error log (hPanel →
Advanced → PHP Configuration, or your app's error log) if something doesn't show up.

---

## Email (Resend)

- **Before domain verification:** Resend only lets you send *to your own Resend account email*, so
  set `NOTIFY_EMAIL` to that address. You'll get every booking and every scan lead; hit reply to
  answer the customer directly.
- **After verifying your domain** in Resend: set `FROM_EMAIL`. Customers then also get a booking
  confirmation and an emailed copy of their scan report.

Without `RESEND_API_KEY`, bookings/leads are only logged to the PHP error log — fine for testing,
not for launch.

## Hardening (recommended)

- **Rate limit the API** — the built-in per-IP limiter in `api/_helpers.php` covers basic abuse;
  for more, ask Hostinger support about WAF/security options on your plan.
- **Cheaper scans:** set `MODEL` to `claude-haiku-4-5-20251001` in `api/config.php`.

---

## Alternate: Cloudflare Workers

Not used by default, but kept in `cloudflare-worker/` in case you ever want Cloudflare's edge
network instead of Hostinger. It implements the same two routes with the same logic as `api/`.

```bash
cd cloudflare-worker
npm install
npx wrangler login
npx wrangler deploy      # then set secrets: npx wrangler secret put ANTHROPIC_API_KEY (etc.)
```

Local dev: `cp .dev.vars.example .dev.vars`, fill in keys, `npm run dev` → http://localhost:8787.
