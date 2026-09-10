# Resumerica

Static site + a tiny Cloudflare Worker backend.

```
public/index.html   the website
src/worker.js       POST /api/scan (Claude ATS scan) and POST /api/book (booking emails)
wrangler.jsonc      Cloudflare config
```

The API key lives only on the server. The browser never sees it, and the scan prompt is fixed
server-side so nobody can use your endpoint as a free general-purpose Claude proxy.

---

## 1. Push to GitHub

```bash
git init
git add .
git commit -m "Initial deploy"
git branch -M main
git remote add origin https://github.com/<you>/resumerica.git
git push -u origin main
```

## 2. Put your domain on Cloudflare (free)

1. Cloudflare dashboard → **Add a domain** → enter your domain → Free plan.
2. Cloudflare shows two nameservers. At your registrar (Namecheap, GoDaddy, etc.), replace the
   existing nameservers with those two.
3. Wait until Cloudflare marks the domain **Active** (usually minutes, can take a few hours).

## 3. Deploy the Worker

**Option A — Git (auto-deploys on every push)**
Workers & Pages → **Create** → **Import a repository** → pick `resumerica`.
Leave the build command empty; deploy command is `npx wrangler deploy`.

**Option B — CLI**
```bash
npm install
npx wrangler login
npx wrangler deploy
```

## 4. Add secrets

Worker → **Settings → Variables and Secrets** → add each as type **Secret**
(plain-text vars get overwritten by `wrangler deploy`; secrets don't):

| Name | Value |
|---|---|
| `ANTHROPIC_API_KEY` | from console.anthropic.com → API Keys |
| `RESEND_API_KEY` | from resend.com → API Keys |
| `NOTIFY_EMAIL` | the inbox that receives bookings + scan leads (see note below) |
| `FROM_EMAIL` | *later*, after step 6 — e.g. `Resumerica <hello@yourdomain.com>` |

CLI equivalent: `npx wrangler secret put ANTHROPIC_API_KEY`

In the Anthropic Console, also set a **monthly spend limit** — this is your real safety net
against anyone hammering the scanner.

## 5. Connect the domain

Worker → **Settings → Domains & Routes → Add → Custom domain** → `yourdomain.com`, then again for
`www.yourdomain.com`. Cloudflare creates the DNS records and SSL certificate automatically.

## 6. Email (Resend)

- **Before domain verification:** Resend only lets you send *to your own Resend account email*,
  so set `NOTIFY_EMAIL` to that address. You'll get every booking and every scan lead; hit
  reply to answer the customer directly.
- **After verifying your domain** (Resend → Domains → add domain → it can auto-add the DNS records
  on Cloudflare): set the `FROM_EMAIL` secret. Customers then also get a booking confirmation and
  an emailed copy of their scan report, and the site says so.

Without `RESEND_API_KEY`, bookings are only written to the Worker logs
(Worker → Observability → Logs) — fine for a quick test, not for launch.

## 7. Hardening (recommended)

- **Rate limit the API:** your domain → Security → WAF → **Rate limiting rules** → match URI path
  starts with `/api/`, limit per IP. The free plan includes one rule.
- **Cheaper scans:** change `MODEL` in `wrangler.jsonc` to `claude-haiku-4-5-20251001`.

## Local development

```bash
cp .dev.vars.example .dev.vars   # fill in keys
npm install
npm run dev                      # http://localhost:8787
```

## Known gap

The nav and footer link to `blog.html`, which isn't in this repo yet. Add `public/blog.html`
or remove the two "Insights" links before launch.
