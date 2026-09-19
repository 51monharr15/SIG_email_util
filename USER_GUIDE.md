# SIG_email_util — User guide

What this system does, how to run it safely, and how to change the email wording.

Forum: https://stroke.logicalmodel.net  
Server folder: `stroke/SIG_email_util/` (own directory — **not** inside Flarum)  
PC folder: `D:\Obsidian\development\SIG_email_util\`  
GitHub: https://github.com/51monharr15/SIG_email_util

---

## What it does

Two separate jobs:

| Job | Script | Typical schedule | Who it targets |
|---|---|---|---|
| **Welcome** | `cron/welcome_new_users.php` | Daily | People who **joined** in the last ~36 hours |
| **Re-engage** | `cron/reengage_inactive.php` | Weekly | People whose **last visit** is older than *n* days (default 30) |

Neither job changes Flarum’s own tables. Tracking uses two extra tables: `sig_mail_log` and `sig_mail_prefs`.

---

## Safety (read this before any real send)

| Setting / flag | Effect |
|---|---|
| `mail.dry_run = true` | **No email is sent.** Only log lines + DB rows with status `dry_run`. |
| `mail.dry_run = false` and `--send` | May send real mail. |
| `mail.allowlist` with addresses | When sending for real, **only** those addresses can receive mail. Others are skipped (not redirected to you). |
| `mail.allowlist` empty `[]` | When sending for real, all eligible users may receive mail. |

**Allowlist is for testing only.** You do **not** add every community member.

Recommended path:

1. Keep `dry_run = true` until logs look right.  
2. Put only your test address in `allowlist`.  
3. One careful `--send` test.  
4. Later: empty allowlist + scheduled `--send` for live use.

---

## Where the email text lives (and how to edit it)

**File:** `lib/messages.php`  
(on PC and on the server — keep them in sync)

| Audience | Function in that file | Notes |
|---|---|---|
| Brand-new members | `sig_welcome_message` | One welcome wording |
| Away ~n to 2n days | `sig_reengage_message` branch **soft** | Default *n* = 30 → about 30–59 days |
| Away ~2n to 3n days | same function, **softer** | About 60–89 days |
| Away 3n+ days | same function, **gentle** | 90+ days (includes people gone hundreds of days) |

How to edit:

1. Edit `lib/messages.php` on the **PC**.  
2. Upload that file with FileZilla to `stroke/SIG_email_util/lib/messages.php`.  
3. (Later) `git add` / `commit` / `push` so GitHub matches.

There is no admin web screen for this text yet — the PHP file **is** the template.

---

## How to see what would be sent (without mailing people)

### Log files (on the server)

After a dry-run cron, open e.g.:

- `welcome_log.txt`
- `reengage_log.txt`

Reengage lines look like:

```text
DRY-RUN reengage [gentle] 641d -> someone@example.com | threads=30 | A quiet hello from Stroke Community
```

That shows: tier, days away, address, thread count, subject.

### Database table `sig_mail_log`

In phpMyAdmin (same DB as Flarum, tables **without** the `flfo_` prefix):

- `kind` — `welcome` or `reengage`
- `tier` — `soft` / `softer` / `gentle` (reengage only)
- `status` — `dry_run` / `sent` / `skipped` / `error`
- `subject`, `skip_reason`, `meta_json` (days away, thread count, etc.)

---

## Config you care about (`config.php` on the server only)

Copy from `config.example.php`. **Never** put real `config.php` on GitHub.

Critical fields:

| Key | Purpose |
|---|---|
| `database.*` | Same DB as Flarum |
| `database.prefix` | Must be `flfo_` for this forum |
| `forum.base_url` | `https://stroke.logicalmodel.net` |
| `forum.name` | Name shown in emails |
| `mail.from_email` | e.g. `admin@stroke.logicalmodel.net` |
| `mail.smtp.*` | Host/port/ssl/user/password for sending |
| `mail.dry_run` | See safety above |
| `mail.allowlist` | See safety above |
| `welcome.lookback_hours` | How far back to look for new joiners (default 36) |
| `reengage.n_days` | Inactivity threshold *n* (default 30) |
| `reengage.extra_n_days` | Reminder list e.g. `[180]` — or `[]` to ignore |
| `reengage.max_threads` | Max discussion links in one email (default 30; lower if too long) |
| `reengage.honour_email_prefs` | If true, skip users with all Flarum *email* notify prefs off (push-only users may be skipped — revisit if needed) |
| `reengage.min_interval_days` | Don’t *really* reengage the same person more often than this |

Email addresses in PHP **must** be in quotes: `'simon@example.com'`.

---

## Cron lines that work on this host

cPanel cron starts in your home directory. Use **relative** paths (this pattern works here):

**Daily welcome (example 09:15) — still dry-run until you choose otherwise:**

```bash
cd stroke/SIG_email_util && /usr/local/bin/php cron/welcome_new_users.php --dry-run >> welcome_log.txt 2>&1
```

**Weekly reengage (example Monday 10:15) — dry-run:**

```bash
cd stroke/SIG_email_util && /usr/local/bin/php cron/reengage_inactive.php --dry-run >> reengage_log.txt 2>&1
```

When ready for real sends, change `--dry-run` to `--send` and set `dry_run` / allowlist appropriately.

Optional deeper pass:

```bash
cd stroke/SIG_email_util && /usr/local/bin/php cron/reengage_inactive.php --dry-run --n-days=180 >> reengage_180_log.txt 2>&1
```

---

## What “done” looks like

- Dry-run welcome: `done: {"candidates":0,...}` if nobody new joined recently.  
- Dry-run reengage: lines with `[soft]` / `[softer]` / `[gentle]` and `sent":0`.  
- Live send: `SENT ...` and `sig_mail_log.status = sent`.

---

## Opt-out table

`sig_mail_prefs`: set `allow_reengage = 0` for a user id to stop reengage mail for that person (welcome is separate / once-only via log).

---

## Appendix — Getting these docs / agent limits

If a Cursor Cloud Agent was started on a **different** GitHub repo, it cannot push files into `SIG_email_util`. That is a hard limit for that chat, not a missing permission tick-box you can fix mid-conversation.

To maintain this project in Cursor later: start a **new** agent and select repository **SIG_email_util**.

If someone mentions “Artifacts” and you do not see that word in the Cursor app, ignore it — open the guide files from GitHub in your web browser instead (Download / Raw / Save As).
