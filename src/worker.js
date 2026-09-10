/**
 * Resumerica — Cloudflare Worker
 * Serves the static site from /public and handles two API routes:
 *   POST /api/scan  -> sends the resume PDF to Claude, returns the ATS report JSON
 *   POST /api/book  -> emails you the consultation request (and optionally confirms to the customer)
 *
 * Secrets (set with `npx wrangler secret put NAME`):
 *   ANTHROPIC_API_KEY   required
 *   RESEND_API_KEY      optional — without it, bookings/leads are only logged
 *   NOTIFY_EMAIL        where booking + lead notifications go (your inbox)
 *   FROM_EMAIL          optional — e.g. "Resumerica <hello@yourdomain.com>" once the domain is verified in Resend.
 *                       When set, customers also get a booking confirmation and a copy of their scan report.
 * Plain vars (wrangler.jsonc): MODEL
 */

const MAX_PDF_BASE64 = 7_000_000; // ~5 MB PDF
const SCHEMA = `{"atsScore":<int 0-100>,"detectedRole":"<inferred target role>","verdict":"<3-6 word verdict>","summary":"<2 sentence plain overview>","categories":[{"name":"Parseability & formatting","score":<int>,"note":"<why this score, 6-10 words>"},{"name":"Section structure","score":<int>,"note":"<6-10 words>"},{"name":"Keyword & role match","score":<int>,"note":"<6-10 words>"},{"name":"Impact & metrics","score":<int>,"note":"<6-10 words>"},{"name":"Contact & links","score":<int>,"note":"<6-10 words>"}],"strengths":["<specific thing already helping the ATS score>","<...>","<...>","<...>"],"issues":[{"problem":"<specific thing hurting the ATS score>","fix":"<the exact change to make>"},{"problem":"<...>","fix":"<...>"},{"problem":"<...>","fix":"<...>"},{"problem":"<...>","fix":"<...>"}],"keywordGaps":["<missing term>","<missing term>","<missing term>","<missing term>"]}`;
const PROMPT = `You are a senior ATS and AI-resume-screening analyst. Analyze the attached resume PDF exactly as a modern AI applicant-tracking system would (Workday/Greenhouse/Ashby-style LLM screening plus classic keyword parsing). Infer the candidate's most likely target role. Judge on clean machine parseability, standard section structure, keyword/role match, quantified impact, and complete contact/links. Give a genuinely detailed read: in "strengths" list 4-6 concrete things ALREADY favoring the ATS score, and in "issues" list 4-6 concrete things HURTING it, each paired with the exact change to make. Be specific to THIS resume, not generic. Be realistic and slightly strict — most real resumes land 45-80. Return ONLY minified JSON, no markdown, no code fences, no preamble, matching exactly: ${SCHEMA}`;

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);

    if (url.pathname.startsWith("/api/")) {
      if (request.method !== "POST") return json({ error: "Method not allowed" }, 405);

      // Only accept calls from our own site (blocks other sites from using your API key via this endpoint)
      const origin = request.headers.get("Origin");
      if (origin && new URL(origin).host !== url.host) return json({ error: "Forbidden" }, 403);

      // Optional per-IP rate limit (see README). Skipped if the binding isn't configured.
      if (env.SCAN_LIMITER) {
        const ip = request.headers.get("CF-Connecting-IP") || "unknown";
        const { success } = await env.SCAN_LIMITER.limit({ key: ip + url.pathname });
        if (!success) return json({ error: "Too many requests — please wait a minute." }, 429);
      }

      try {
        if (url.pathname === "/api/scan") return await handleScan(request, env, ctx);
        if (url.pathname === "/api/book") return await handleBook(request, env);
        return json({ error: "Not found" }, 404);
      } catch (err) {
        console.error(err);
        return json({ error: "Server error" }, 500);
      }
    }

    return env.ASSETS.fetch(request);
  },
};

async function handleScan(request, env, ctx) {
  if (!env.ANTHROPIC_API_KEY) return json({ error: "Scanner not configured" }, 500);

  const body = await request.json().catch(() => null);
  const pdf = body?.pdf;
  if (typeof pdf !== "string" || pdf.length < 100) return json({ error: "Missing PDF" }, 400);
  if (pdf.length > MAX_PDF_BASE64) return json({ error: "PDF too large (max 5 MB)" }, 413);
  if (!pdf.startsWith("JVBER")) return json({ error: "That file isn't a valid PDF" }, 400); // base64 of "%PDF"

  const email = cleanEmail(body.email);

  const res = await fetch("https://api.anthropic.com/v1/messages", {
    method: "POST",
    headers: {
      "content-type": "application/json",
      "x-api-key": env.ANTHROPIC_API_KEY,
      "anthropic-version": "2023-06-01",
    },
    body: JSON.stringify({
      model: env.MODEL || "claude-sonnet-5",
      max_tokens: 2000,
      messages: [{
        role: "user",
        content: [
          { type: "document", source: { type: "base64", media_type: "application/pdf", data: pdf } },
          { type: "text", text: PROMPT },
        ],
      }],
    }),
  });

  if (!res.ok) {
    console.error("Anthropic error", res.status, await res.text());
    return json({ error: "Scan failed" }, 502);
  }

  const data = await res.json();
  const text = (data.content || []).filter(b => b.type === "text").map(b => b.text).join("");
  const clean = text.replace(/```json/gi, "").replace(/```/g, "").trim();
  let report;
  try {
    report = JSON.parse(clean.slice(clean.indexOf("{"), clean.lastIndexOf("}") + 1));
  } catch {
    return json({ error: "Could not read scan result" }, 502);
  }
  if (typeof report.atsScore !== "number") return json({ error: "Could not read scan result" }, 502);

  report._emailed = false;
  if (email) {
    // Lead notification to you — don't make the visitor wait on it
    ctx.waitUntil(sendMail(env, {
      to: env.NOTIFY_EMAIL,
      subject: `New scan lead: ${email} — ATS ${report.atsScore}`,
      html: leadHtml(email, report, body.fileName),
      reply_to: email,
    }));
    // Copy of the report to the visitor (only once your domain is verified in Resend)
    if (env.FROM_EMAIL && env.RESEND_API_KEY) {
      report._emailed = await sendMail(env, {
        to: email,
        subject: `Your Resumerica ATS report — ${report.atsScore}/100`,
        html: reportHtml(report),
      });
    }
  }

  return json(report);
}

async function handleBook(request, env) {
  const b = await request.json().catch(() => null);
  if (!b) return json({ error: "Bad request" }, 400);
  if (b.website) return json({ ok: true, confirmed: false }); // honeypot filled -> bot, pretend success

  const name = clip(b.name, 100), email = cleanEmail(b.email);
  if (!name || !email) return json({ error: "Name and a valid email are required" }, 400);
  const f = {
    name, email,
    phone: clip(b.phone, 40), date: clip(b.date, 20), time: clip(b.time, 20),
    service: clip(b.service, 120) || "General consultation", goal: clip(b.goal, 2000),
  };

  const notified = await sendMail(env, {
    to: env.NOTIFY_EMAIL,
    subject: `New booking request: ${f.name} — ${f.service}`,
    html: bookingHtml(f),
    reply_to: f.email,
  });
  if (!notified) {
    console.log("BOOKING (email not configured):", JSON.stringify(f));
    if (env.RESEND_API_KEY) return json({ error: "Could not send request" }, 502);
  }

  let confirmed = false;
  if (env.FROM_EMAIL && env.RESEND_API_KEY) {
    confirmed = await sendMail(env, {
      to: f.email,
      subject: "We received your Resumerica consultation request",
      html: `<p>Hi ${esc(f.name.split(" ")[0])},</p>
<p>Thanks for reaching out about <b>${esc(f.service)}</b>. We've received your request${f.date ? ` for ${esc(f.date)} at ${esc(f.time)}` : ""} and will confirm the exact time shortly.</p>
<p>— The Resumerica team</p>`,
      reply_to: env.NOTIFY_EMAIL,
    });
  }
  return json({ ok: true, confirmed });
}

/* ---------- helpers ---------- */

async function sendMail(env, { to, subject, html, reply_to }) {
  if (!env.RESEND_API_KEY || !to) return false;
  const res = await fetch("https://api.resend.com/emails", {
    method: "POST",
    headers: { Authorization: `Bearer ${env.RESEND_API_KEY}`, "Content-Type": "application/json" },
    body: JSON.stringify({
      from: env.FROM_EMAIL || "Resumerica <onboarding@resend.dev>",
      to: [to], subject, html, ...(reply_to ? { reply_to } : {}),
    }),
  });
  if (!res.ok) console.error("Resend error", res.status, await res.text());
  return res.ok;
}

function json(obj, status = 200) {
  return new Response(JSON.stringify(obj), {
    status, headers: { "Content-Type": "application/json", "Cache-Control": "no-store" },
  });
}
function clip(v, n) { return typeof v === "string" ? v.trim().slice(0, n) : ""; }
function cleanEmail(v) {
  const e = clip(v, 200);
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e) ? e : "";
}
function esc(s) {
  return String(s ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}
function row(k, v) { return v ? `<tr><td style="padding:4px 12px 4px 0;color:#6A7286">${k}</td><td>${esc(v)}</td></tr>` : ""; }

function bookingHtml(f) {
  return `<h2 style="font-family:Georgia,serif;color:#14264A">New consultation request</h2>
<table style="font-family:Arial,sans-serif;font-size:14px">${row("Name", f.name)}${row("Email", f.email)}${row("Phone", f.phone)}
${row("Service", f.service)}${row("Preferred date", f.date)}${row("Time", f.time)}${row("Goal", f.goal)}</table>
<p style="font-family:Arial,sans-serif;font-size:13px;color:#6A7286">Hit reply to respond directly to ${esc(f.email)}.</p>`;
}

function leadHtml(email, r, fileName) {
  return `<h2 style="font-family:Georgia,serif;color:#14264A">New ATS scan lead</h2>
<table style="font-family:Arial,sans-serif;font-size:14px">${row("Email", email)}${row("File", fileName)}
${row("ATS score", String(r.atsScore))}${row("Detected role", r.detectedRole)}${row("Verdict", r.verdict)}</table>
${reportHtml(r)}`;
}

function reportHtml(r) {
  const cats = (r.categories || []).map(c => `<li>${esc(c.name)}: <b>${esc(c.score)}</b> — ${esc(c.note)}</li>`).join("");
  const str = (r.strengths || []).map(s => `<li>${esc(s)}</li>`).join("");
  const iss = (r.issues || []).map(i => `<li><b>${esc(i.problem)}</b><br>Fix: ${esc(i.fix)}</li>`).join("");
  const gaps = (r.keywordGaps || []).map(esc).join(", ");
  return `<div style="font-family:Arial,sans-serif;font-size:14px;color:#14264A;line-height:1.5">
<h3>ATS score: ${esc(r.atsScore)}/100 — ${esc(r.verdict)}</h3><p>${esc(r.summary)}</p>
<h4>Breakdown</h4><ul>${cats}</ul>
<h4>What's working</h4><ul>${str}</ul>
<h4>What to fix</h4><ul>${iss}</ul>
${gaps ? `<h4>Missing keywords</h4><p>${gaps}</p>` : ""}</div>`;
}
