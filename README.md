# Resumerica

Static site with a small PHP backend for one API route: a free CV-review lead capture (resume
upload, emailed to your team with the file attached, plus a confirmation to the visitor). Sends
email via Resend. Consultation booking is handled by Calendly (embedded popup), not by this
backend.

```
index.html                 the website
blog.html                  Insights page
api/                       PHP backend, review.php, config.php
cloudflare-worker/         old Cloudflare Worker backend, unused, kept only for reference
```

**The repo root is the deployable web root**, that's what makes Hostinger's Git deploy work
without any extra steps (see below).

`cloudflare-worker/` reflects an earlier version of this project (an AI resume-scan feature that
has since been removed) and is **not kept in sync** with `api/`, it's blocked from public access
by `.htaccess` and isn't part of the current deploy. Ignore it unless you specifically want to
revive a Cloudflare-based deployment from scratch.

---

## Deploy to Hostinger (shared hosting)

### 1. Configure secrets

```bash
cp api/config.example.php api/config.php
```

Edit `api/config.php` and fill in:

| Constant | Value |
|---|---|
| `RESEND_API_KEY` | from resend.com, API Keys (without it, CV review requests only go to the PHP error log, not your inbox) |
| `NOTIFY_EMAIL` | the inbox that receives CV review requests |
| `FROM_EMAIL` | *later*, once your domain is verified in Resend, e.g. `Resumerica <hello@yourdomain.com>` |

**Common mistake:** `define('NAME', 'value')` takes the setting's name first and your real value
second, e.g. `define('RESEND_API_KEY', 're_your_real_key');`. Don't replace `'RESEND_API_KEY'`
itself with your key; fill in the second, empty `''`.

`config.php` is gitignored, it never gets committed or pushed, so it has to be uploaded/created
separately from the Git deploy (step 2 explains). Save it as plain **UTF-8 without BOM**
(Notepad++, VS Code, or any code editor is fine; plain Windows Notepad can silently add a BOM that
breaks the API responses).

### 2. Deploy via hPanel → Git

1. hPanel → your website → **Advanced → Git** (or **Git** under Websites, depending on your
   hPanel layout).
2. **Create a new repository** (or "Add repository"):
   - Repository URL: `https://github.com/aliazeem8090-dev/Resumerica.git`
   - Branch: `main`
   - **Directory**: leave this blank / set to `public_html`, the repo root has to land directly
     in `public_html`, not a subfolder, since `index.html` and `api/` live at the repo root.
3. Hostinger requires the target directory to be **empty** on the first deploy. If `public_html`
   already has a default Hostinger placeholder page in it, delete those files first (File
   Manager), then deploy.
4. Click **Deploy**. Every future `git push` to `main` can be redeployed the same way (either a
   manual "Deploy" click in hPanel, or a webhook if your plan/version of the Git feature offers
   one, check the panel for a "Deploy on push" or webhook URL option).
5. **`config.php` will not come from Git** (it's gitignored on purpose, real keys never touch the
   repo). After the first deploy, go to File Manager → `public_html/api/` and either:
   - upload your locally-filled `config.php` via File Manager's upload button, or
   - create `config.php` directly in File Manager's code editor and paste in the contents of
     `config.example.php` with your real values filled in.

### 3. Make the `data/` folder writable

`public_html/api/data/` needs to be writable by PHP, it's used for lightweight per-IP rate
limiting. Via File Manager, set its permissions to `755` (or `775` if 755 doesn't work on your
setup). If PHP can't write to it, rate limiting just silently no-ops, it fails open, not closed,
so this step is optional hardening, not a requirement to launch.

### 4. Point your domain at Hostinger

If Hostinger is your registrar or you already use Hostinger's nameservers for this domain, this is
likely already done. Otherwise: hPanel → Domains → point the domain's nameservers or A record at
your Hostinger hosting.

### 5. Verify it's live

Visit your domain, submit a test CV review request, and click "Book consultation" to confirm the
Calendly popup opens. Check your `NOTIFY_EMAIL` inbox, and the PHP error log (hPanel → Advanced →
PHP Configuration, or your app's error log) if the CV review request doesn't show up.

---

## Consultation booking (Calendly)

"Book consultation" buttons across the site open a Calendly popup widget pointed at
`https://calendly.com/consultation-resumerica/30min` (see `openCalendly()` in `index.html`). This
is entirely client-side, Calendly handles availability, confirmations, reminders and video links
on its own; there's no PHP backend involved for bookings. To change the event, update the URL in
that one function and in the `<script src="https://assets.calendly.com/assets/external/widget.js">`
tag in the `<head>`.

## Email (Resend)

- **Before domain verification:** Resend only lets you send *to your own Resend account email*, so
  set `NOTIFY_EMAIL` to that address. You'll get every CV review request; hit reply to answer the
  person directly.
- **After verifying your domain** in Resend: set `FROM_EMAIL`. Visitors then also get their own
  confirmation email ("your CV is being reviewed").
- Sending from Resend's shared `onboarding@resend.dev` address (before `FROM_EMAIL` is set) often
  lands in spam, that's expected, not a bug. Verifying your domain fixes deliverability.

Without `RESEND_API_KEY`, CV review requests are only logged to the PHP error log, fine for
testing, not for launch.

## Hardening (recommended)

- **Rate limit the API**, the built-in per-IP limiter in `api/_helpers.php` covers basic abuse;
  for more, ask Hostinger support about WAF/security options on your plan.
- **File size**, CV review uploads are capped at 8 MB (`MAX_FILE_BYTES` in `api/review.php`).
