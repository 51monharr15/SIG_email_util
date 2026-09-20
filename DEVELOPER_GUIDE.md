# SIG_email_util — Developer / maintainer guide

**Product rules, restart context, leftovers:** see **`RESTART.md`** first.

---

## 1. Repositories (do not mix)

| Place | Role |
|---|---|
| https://github.com/51monharr15/SIG_email_util | Code home |
| `D:\Obsidian\development\SIG_email_util\` | PC copy |
| `stroke/SIG_email_util/` on host | Live runnable (FileZilla) — **not** inside Flarum |
| SIG-Stakeholder-Database | **Different** project |

Cloud Agents only write to the repo they were started on → use **SIG_email_util**.

---

## 2. Layout

```text
SIG_email_util/
  RESTART.md              # pick-up doc after a break
  config.example.php
  config.php              # secrets — never GitHub
  templates/
    welcome.html
    reminders.html        # bands: digest / away / long / dormant
  lib/
    bootstrap.php         # config, PDO, state, bands
    mailer.php
    messages.php
  cron/
    welcome_new_users.php
    reengage_inactive.php # reminder job (filename kept for cron paths)
    install_mail_tables.php
  sql/sig_mail_tables.sql
```

---

## 3. Day-to-day

### Copy
Edit `templates/*.html` → FileZilla upload → `git commit` / `push`.

### Behaviour / bands
Edit server `config.php` `reminders.bands` (and `never_engaged`). Do not commit secrets. Update `config.example.php` for non-secret defaults only.

### Deploy code
PC → commit/push → FileZilla upload changed files (not `.git`). Keep server `config.php`.

---

## 4. Database

- **`sig_mail_state`** — only utility table (welcome + last reminder kind/date).  
- Install SQL **drops** unused test tables `sig_mail_log` / `sig_mail_prefs` if present.  
- Flarum tables: prefix `flfo_`. Utility table: **no** `flfo_` prefix.

Install: `php cron/install_mail_tables.php` (or phpMyAdmin + `sql/sig_mail_tables.sql`).

---

## 5. Cron on this host

Proven absolute path + timezone (Options has no `date.timezone`):

```bash
/usr/local/bin/php -d date.timezone=Europe/London /home/logicalm/stroke/SIG_email_util/cron/install_mail_tables.php >> /home/logicalm/stroke/SIG_email_util/install_log.txt 2>&1
```

Same pattern for welcome / `reengage_inactive.php`. See `USER_GUIDE.md` and `*.cron.txt`.

---

## 6. CLI flags

| Flag | Meaning |
|---|---|
| `--dry-run` | File log only; no send; no state update |
| `--send` | Allow send |
| `--limit=N` | Cap users (testing) |
| `--user-id=N` | One user |
| `--help` | Usage |

Scripts refuse browser execution.

---

## 7. False steps worth remembering

| Symptom | Fix |
|---|---|
| Parse error `@` expecting `]` | Quote emails in config |
| Table `users` not found | `'prefix' => 'flfo_'` |
| Timezone Startup warnings in `error.log` | `-d date.timezone=Europe/London` on cron |
| git-ftp fails | Use FileZilla |
| Agent can’t push | Start agent on **SIG_email_util** |
