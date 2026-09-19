# SIG_email_util — Developer / maintainer guide

Simple map of **what**, **where**, and **how** — including lessons from the first live install.

---

## 1. The right repositories (do not mix)

| Place | Role |
|---|---|
| https://github.com/51monharr15/SIG_email_util | **Only** home for this email utility |
| `D:\Obsidian\development\SIG_email_util\` | Your PC copy of that repo (edit here, then upload / push) |
| `stroke/SIG_email_util/` on the web host | Live runnable copy (FTP). **Own folder** — not inside Flarum |
| https://github.com/51monharr15/SIG-Stakeholder-Database | **Different** project (`lusers.php`, etc.). Do **not** put email-util work there |

Flarum itself lives under something like `stroke/flarum/` and has its **own** `config.php` (leave it alone).

### Cloud Agent / Cursor

A Cloud Agent can only **write** to the GitHub repo it was **started on**.  
If a chat was started on `SIG-Stakeholder-Database`, it cannot push to `SIG_email_util`.  
For code changes to this utility, start (or reopen) an agent on **`SIG_email_util`**.

---

## 2. Layout on disk

```text
SIG_email_util/
  config.example.php    # template — committed
  config.php            # secrets — server (+ optional PC); NEVER GitHub
  USER_GUIDE.md
  DEVELOPER_GUIDE.md
  lib/
    bootstrap.php       # config, DB, args, logging helpers
    mailer.php          # SMTP / mail()
    messages.php        # *** email wording / tiers ***
  cron/
    welcome_new_users.php
    reengage_inactive.php
    install_mail_tables.php
  sql/
    sig_mail_tables.sql
```

---

## 3. What not to put on the server

| Item | Why |
|---|---|
| `.git/` | Not needed to run; clutter / risk. Delete from server if FileZilla uploaded it. |
| PC-only junk | Keep server = PHP + config + logs |

**Do** keep on server: `config.php`, `cron/`, `lib/`, `sql/` (optional once tables exist).

Suggested local `.gitignore` already ignores `config.php` and `*.log`.

---

## 4. Day-to-day maintenance loop

### Change email wording

1. Edit `lib/messages.php` on the PC.  
2. FileZilla → upload that file to `stroke/SIG_email_util/lib/`.  
3. Git Bash in the PC folder:

```bash
git add lib/messages.php
git commit -m "Update email copy"
git push
```

### Change behaviour / thresholds

1. Edit `config.php` **on the server** (and keep a matching copy on PC if you want).  
2. Do **not** commit real secrets to GitHub. Update `config.example.php` only for non-secret defaults.

### Deploy other code changes

1. Change files on PC → `git commit` → `git push`.  
2. FileZilla upload the changed files (not `.git`, not necessarily `config.php` if server already has secrets).

`git-ftp` was painful here (TLS). **FileZilla** is the reliable deploy path for this host.

---

## 5. Database

- Flarum tables: prefix **`flfo_`** (e.g. `flfo_users`).  
- Utility tables: **`sig_mail_log`**, **`sig_mail_prefs`** (no `flfo_` prefix).  
- Collation used: `utf8mb4_unicode_ci` (match other forum tables).  
- Never alter Flarum core tables for this project.

Install tables via `sql/sig_mail_tables.sql` in phpMyAdmin **or**  
`php cron/install_mail_tables.php` (safe to re-run: `IF NOT EXISTS`).

---

## 6. Cron on this cPanel host (what actually works)

- Working pattern: `cd stroke/SIG_email_util && /usr/local/bin/php cron/….php >> some_log.txt 2>&1`  
- Absolute `/home/logicalm/...` paths failed in practice; **relative `cd stroke/...` works**.  
- Cron email goes to the address set in cPanel Cron Jobs (here: often `simon@logicalmodel.net`), not automatically to `admin@stroke…`.  
- Always append to a log file so you are not dependent on mail delivery.

PHP CLI timezone warning (`date.timezone` empty): set in cPanel **MultiPHP INI Editor** (e.g. `Europe/London`). Harmless but noisy until fixed.

---

## 7. How the code decides tiers

`sig_reengage_tier($daysAway, $n)` in `lib/messages.php`:

- `daysAway >= 3n` → `gentle`
- `daysAway >= 2n` → `softer`
- else → `soft`

Default `n = 30` from config (override with `--n-days=`).

Welcome is a **different script** and different copy — not a reengage tier.

---

## 8. CLI flags

| Flag | Meaning |
|---|---|
| `--dry-run` | Force no send |
| `--send` | Allow send (still subject to config dry_run/allowlist) |
| `--limit=N` | Process at most N users (testing) |
| `--user-id=N` | Restrict to one user id (**still** respects joined/last-seen filters) |
| `--n-days=N` | Reengage threshold override |
| `--help` | Usage |

Scripts refuse non-CLI (browser) execution.

---

## 9. First-install checklist (condensed — what finally worked)

1. Own GitHub repo `SIG_email_util` + PC folder linked with `git push`.  
2. FileZilla upload project into `stroke/SIG_email_util/` (not into Flarum).  
3. `config.php` from example: DB + `prefix = 'flfo_'` + SMTP + quoted allowlist emails.  
4. Create `sig_mail_*` tables (phpMyAdmin SQL was fine).  
5. Prove cron with log redirect; use `cd stroke/SIG_email_util && …`.  
6. Dry-run welcome / reengage; read logs + `sig_mail_log`.  
7. Only then consider `--send`.

### False steps worth remembering

| Symptom | Cause | Fix |
|---|---|---|
| Parse error `@` expecting `]` | Email in config without quotes | `'user@domain'` |
| Table `users` not found | Empty table prefix | `'prefix' => 'flfo_'` |
| Cron “does nothing” / no log in project dir | Wrong path style | `cd stroke/SIG_email_util && …` |
| git-ftp login fails | Explicit FTPS vs `ftps://` | Use FileZilla instead |
| Agent can’t push to new repo | Agent started on other repo | New agent on `SIG_email_util` |
| Dry-run log only “starting” | Crash after start | Check `error_log` |

---

## 10. Suggested consolidations (product / process)

1. **Keep dry-run scheduled** (daily/weekly) until you explicitly go live — cheap confidence.  
2. **Lower `max_threads`** (e.g. 10–15) if 30-link emails feel heavy.  
3. **Revisit `honour_email_prefs`** if many members use push and have email notifies off.  
4. **Delete `.git` on the server** if present.  
5. **One “source of truth” rule:** edit on PC → upload → push GitHub. Don’t edit only on the server.  
6. **Future:** a tiny `--preview-user=ID` that prints full HTML body to a file would make copy review easier (not built yet).  
7. **Future:** new Cloud Agents for this app must select repo **`SIG_email_util` only**.

---

## 11. Related but separate

Flarum’s own scheduler (`php flarum schedule:run`) is **forum plumbing**, not this mailer. Enable it separately if extensions need it; these email jobs use their **own** cron lines.

---

## 12. Why one Cursor chat cannot push to SIG_email_util

A Cloud Agent run is tied to **one** GitHub repository when it starts.

- This long “email” thread was started on **SIG-Stakeholder-Database**.
- Its write token only works for that repo.
- Even if the Cursor GitHub App is allowed on **all** repositories, **this chat** still cannot push to `SIG_email_util`.
- That is not something you forgot to click. **You cannot unlock write access for this existing chat.**

**What works instead:**

1. Start a **new** Cloud Agent and choose repo **SIG_email_util**, then ask it to add/edit files; or
2. Download files from GitHub in the browser and save them into `D:\Obsidian\development\SIG_email_util\`, then `git add` / `commit` / `push` from Git Bash; or
3. Edit files yourself on the PC in that folder.

“Artifacts” are an optional download area on some **cursor.com** agent pages. If you only use the Cursor **desktop app** and do not see the word Artifacts, ignore it — use GitHub download or a new agent instead.
