# SIG_email_util — User guide

Forum: https://stroke.logicalmodel.net  
Server: `stroke/SIG_email_util/`  
PC: `D:\Obsidian\development\SIG_email_util\`  
GitHub: https://github.com/51monharr15/SIG_email_util  

**Returning after a break?** Start with **`RESTART.md`** — product rules, deploy steps, leftovers.

---

## What it does

| Job | Script | Who |
|---|---|---|
| **Welcome** | `cron/welcome_new_users.php` | Recent joiners (not already active) |
| **Reminders** | `cron/reengage_inactive.php` | **Every** member classified by days since last visit |

Reminder bands (defaults — change in `config.php` → `reminders.bands`):

| Band | Default days away | Meaning |
|---|---|---|
| *(active)* | under 14 | Nothing — still around |
| `digest` | 14–44 | What’s new since last visit |
| `away` | 45–90 | Haven’t been around lately |
| `long` | 91–180 | Stronger reminder |
| `dormant` | 181+ | Long absence / how to find us again |

Long/dormant sends are spaced about **once a quarter** (90 days) by default.

---

## Safety

| Setting | Effect |
|---|---|
| `mail.dry_run` / `--dry-run` | **No email.** File log only. |
| `--send` | May send (still respects allowlist) |
| `mail.allowlist` | When sending for real, only listed addresses |

---

## Where copy lives

- `templates/welcome.html`  
- `templates/reminders.html` (four bands)  

Edit HTML (TryIt/WYSIWYG is fine). Plain text is derived automatically. Keep `{{placeholders}}` and `<!-- band:… -->` markers.

---

## Logs vs database

- **File log:** every person — `NOTHING … because …` or `WOULD-SEND` / `SENT` with band + subject (not full body).  
- **Database `sig_mail_state`:** only after a **real** send (welcome time and/or last reminder kind + date).

---

## Cron (this host)

```bash
/usr/local/bin/php -d date.timezone=Europe/London /home/logicalm/stroke/SIG_email_util/cron/install_mail_tables.php >> /home/logicalm/stroke/SIG_email_util/install_log.txt 2>&1
```

Select PHP Version → Options has **no** `date.timezone`; the `-d` flag is required. See `welcome.cron.txt` / `reengage.cron.txt`.

---

## First deploy after this restart

1. Upload code + merge `reminders` into server `config.php`.  
2. Run install (creates `sig_mail_state`).  
3. Dry-run reminders; read the log.  
4. Only then `--send` with allowlist.
