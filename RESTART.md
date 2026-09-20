# RESTART — SIG_email_util

**Date frozen:** 20 Sep 2026  
**Purpose:** Pick this up after a week without re-deriving the design from chat.

Repo: https://github.com/51monharr15/SIG_email_util  
PC: `D:\Obsidian\development\SIG_email_util\`  
Live: `logicalm@logicalmodel.net` → `stroke/SIG_email_util/`  
Forum: https://stroke.logicalmodel.net  

---

## What this tool is for (agreed product)

Simple outbound mailer for the Stroke Community Flarum forum:

1. **Welcome** — brand-new joiners: feel welcome, profile how-to, where to look.  
2. **Reminders / digests** — based on **days since last visit**, with absolute day bands in config (not n / 2n / 3n).

### Default bands (editable in `config.php` → `reminders.bands`)

| Band id | Days since last visit (default) | Intent | Min gap between real sends |
|---|---|---|---|
| *(none)* | under 14 | Active → **nothing** | — |
| `digest` | 14–44 | What’s happened since you looked | 14 days |
| `away` | 45–90 | We notice you haven’t been around | 30 days |
| `long` | 91–180 | Stronger reminder | **90 days** (≈ quarter) |
| `dormant` | 181+ | You still have a login / how to find us | **90 days** |

These numbers were **illustrative then adopted as code defaults** so the tool runs; change them freely in config.

### Never-engaged “priming”

If `joined_at` and `last_seen_at` are within **7 days** of each other, and that was **≥ 100 days ago**, treat as **`dormant`** (find-us), not welcome.  
Config: `reminders.never_engaged`.

### Welcome vs active

Welcome job only looks at recent joiners (lookback hours). If they already look active (last visit meaningfully after join), log **nothing because already active** and do not welcome.

---

## Logging rules (agreed)

| Place | What goes there |
|---|---|
| **File log** (cron `>> …_log.txt`) | **Everybody** considered: `NOTHING … because …` or `WOULD-SEND` / `SENT` with **band name**, days away, subject — **not** full email bodies |
| **Database** | **Real contacts only** (successful sends). Dry-runs and “nothing because” stay in the file |

After a **send** run, rows in `sig_mail_state` with `updated_at` = today were contacted; untouched rows still show the previous contact (e.g. not another reminder this run).

---

## Database (agreed model)

### `sig_mail_state` (only table we keep)

| Column | Meaning |
|---|---|
| `user_id` | Flarum numeric id (stable key) |
| `welcome_sent_at` | When welcome was really sent (or null) |
| `last_kind` | Last reminder band id: `digest` / `away` / `long` / `dormant` |
| `last_sent_at` | When that reminder was really sent |
| `updated_at` | Last time this state row changed |

Display name for emails = nickname if set, else username. **Not** stored as the key.

Old test-era tables `sig_mail_log` and `sig_mail_prefs` are **dropped** by `sql/sig_mail_tables.sql` / install — they were never live.

On deploy: run the SQL in phpMyAdmin or `cron/install_mail_tables.php`.

---

## What was removed / replaced from the old design

| Old | New |
|---|---|
| Only people inactive ≥ 30 days | Everyone classified; active = nothing because active |
| Tiers soft/softer/gentle via n, 2n, 3n | Named bands with absolute min/max days in config |
| `kind=reengage` + `tier=…` | `last_kind` = band name |
| `extra_n_days=[180]` log note | Gone — did nothing useful |
| Dry-runs written to DB | File log only |
| Honour Flarum email prefs (default on) | **Off by default** (open topic — see leftovers) |

---

## Templates

- `templates/welcome.html` — welcome copy  
- `templates/reminders.html` — four bands: `digest`, `away`, `long`, `dormant`  
  Markers: `<!-- band:digest subject:… -->` … `<!-- /band:digest -->`  
- HTML is source of truth; plain text is derived  
- Placeholders documented in HTML comments  

---

## Cron (this host)

Always include timezone override (Select PHP Version → Options has **no** `date.timezone`):

```bash
/usr/local/bin/php -d date.timezone=Europe/London /home/logicalm/stroke/SIG_email_util/cron/….php …
```

Examples in `welcome.cron.txt`, `reengage.cron.txt` (reminder job still named `reengage_inactive.php` so existing cron paths keep working).

---

## Deploy checklist when you return

1. Read this file + skim `config.example.php` `reminders` section.  
2. Merge new keys into **server** `config.php` (bands, never_engaged, timezone).  
3. Upload: `lib/`, `cron/`, `templates/`, `sql/`, docs.  
4. Run install once:  
   `…/php -d date.timezone=Europe/London …/cron/install_mail_tables.php >> …/install_log.txt 2>&1`  
5. Dry-run reminders; read `reengage_log.txt` — expect a line per user.  
6. Only then consider `--send` + allowlist.

---

## Leftovers / not decided (do not assume)

1. **Permission / “email flags”** — you may still want a clear rule later; not implemented as “Flarum notify all off = skip” by default anymore.  
2. **Exact band day numbers** — defaults are editable; revisit 45 vs 60, etc.  
3. **Digest contents** — still auto “new since visit” (count + first N links) + popular; curated key posts / intro thread **not** built.  
4. **Different 180-day follow-up because they already got a 90-day mail** — possible later from `last_kind` + history; **not** built as a special second message. Quarterly gap covers “don’t nag every month.”  
5. **Git commit / GitHub push / live FileZilla upload** of this restart work — do when you resume if not done.  
6. **Remove hourly install cron** once `sig_mail_state` exists (install is safe to re-run but unnecessary forever).

---

## How to dry-run (what you should see)

```text
NOTHING uid=… email=… : active (5d < 14)
NOTHING uid=… : too soon (last=dormant on 2026-07-01, need 90d gap)
WOULD-SEND dormant uid=… 500d | subject…
WOULD-SEND digest uid=… 20d | subject…
SENT …   (only with --send)
```

No full HTML bodies in the log.

---

## Code map (short)

| Path | Role |
|---|---|
| `config.php` / `config.example.php` | Secrets + bands |
| `lib/bootstrap.php` | Config, DB, state helpers, args |
| `lib/messages.php` | Templates + band/welcome render |
| `lib/mailer.php` | SMTP |
| `cron/welcome_new_users.php` | Welcome job |
| `cron/reengage_inactive.php` | Reminder/digest job (all users) |
| `cron/install_mail_tables.php` | Create tables |
| `templates/*.html` | Copy |
| `RESTART.md` | This file |
