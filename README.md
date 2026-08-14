# Glycotrack 🩸

**Personal Diabetic Record Management System**

A self-hosted, single-file PHP web app for tracking blood glucose readings, managing medical reports, and keeping a health journal — built specifically for diabetics who want full control over their data, no subscriptions, no cloud accounts.

> ⚠️ **Medical Disclaimer:** Glycotrack is a personal record-keeping tool only. It is not a medical device and must not be used as a substitute for professional medical advice. Always consult your physician for medical decisions.

---

## Features

- **Glucose Logging** — log fasting, post-meal, random, and bedtime readings with time, method, symptoms, mood, and energy level
- **Dashboard** — live stats: last reading, average glucose, estimated HbA1c, in-range %, logging streak, fasting/post-meal averages, highest/lowest recorded
- **30-Day Trend Charts** — smooth line chart and gradient bar chart with target zone overlay; swipeable on mobile
- **Report Vault** — upload and store medical reports (PDF/JPG): HbA1c, KFT, LFT, lipid profiles, ECG, and more; shareable via token-secured public links
- **Health Journal** — categorized notes (symptoms, medication, diet, exercise, mood, doctor visits) with IST timestamps
- **Patient Profile** — BMI calculator, diagnosis details, doctor info, lifetime statistics
- **CSV Export** — one-click download of all glucose logs
- **Glucose Targets** — configurable low/high thresholds; readings flagged accordingly
- **Single-user auth** — password-protected with session management and password change
- **IST Clock** — live Indian Standard Time displayed in the topbar
- **Mobile-first** — responsive layout, collapsible sidebar, touch-friendly charts

---

## Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP 7.4+ |
| Database | SQLite 3 (via PDO) |
| Frontend | Vanilla HTML/CSS/JS + inline SVG charts |
| Font | Noto Sans (Google Fonts) |
| Storage | Local filesystem (`/data`, `/vault`) |
| Auth | PHP sessions + `password_hash` |

No Composer. No npm. No framework. Zero external dependencies beyond PHP and a web server.

---

## Requirements

- PHP **7.4 or higher** with the following extensions enabled:
  - `pdo`
  - `pdo_sqlite`
  - `sqlite3`
  - `fileinfo` (for vault uploads)
  - `session`
- A web server: **Apache**, **Nginx**, or PHP's built-in dev server
- Write permission on the project directory (for `/data` and `/vault` auto-creation)

---

## Quick Start (Local)

```bash
# 1. Clone the repo
git clone https://github.com/psvineet/glycotrack.git
cd glycotrack

# 2. Start PHP's built-in server
php -S localhost:8080

# 3. Open in browser
# http://localhost:8080
```

First visit triggers the **one-time setup wizard** — create your account and fill in your health profile. You're in.

---

## Setup Guide

### Option A — PHP Built-in Server (Development / Local)

```bash
git clone https://github.com/psvineet/glycotrack.git
cd glycotrack
php -S 0.0.0.0:8080
```

Access at `http://localhost:8080` or `http://YOUR_LOCAL_IP:8080` from any device on the same network (useful for phone access).

---

### Option B — Apache (Shared Hosting / VPS)

1. Upload `index.php` to your web root (e.g. `public_html/glycotrack/`)
2. Ensure the directory is writable by the web server:
   ```bash
   chmod 755 /path/to/glycotrack
   ```
3. Apache needs `mod_rewrite` if you use `.htaccess` rewrites, but Glycotrack works without it — no `.htaccess` required.
4. Visit `https://yourdomain.com/glycotrack/` — setup wizard appears on first load.

**Recommended `.htaccess`** (optional — restricts direct access to data files):

```apache
<FilesMatch "\.(db|sqlite)$">
    Order deny,allow
    Deny from all
</FilesMatch>

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^data/ - [F,L]
    RewriteRule ^vault/ - [F,L]
</IfModule>
```

---

### Option C — Nginx

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/glycotrack;
    index index.php;

    # Block direct access to data and vault directories
    location ~ ^/(data|vault)/ {
        deny all;
        return 403;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

Adjust the PHP-FPM socket path to match your installed PHP version.

---

### Option D — Docker (one-liner)

```bash
docker run -d \
  -p 8080:80 \
  -v $(pwd)/data:/var/www/html/data \
  -v $(pwd)/vault:/var/www/html/vault \
  --name glycotrack \
  php:8.2-apache \
  sh -c "docker-php-ext-install pdo pdo_sqlite && apache2-foreground"
```

> Or use the included `Dockerfile` if present in the repo.

---

## Directory Structure

```
glycotrack/
├── index.php          # Entire application — one file
├── data/
│   └── diabetic.db    # SQLite database (auto-created on first run)
├── vault/             # Uploaded medical reports (auto-created)
└── README.md
```

The `data/` and `vault/` directories are created automatically on first run. **Do not expose these directories publicly** — see the Apache/Nginx config above to block direct access.

---

## Security Notes

- All data is stored **locally** on your server — nothing leaves your machine
- Passwords are hashed with `password_hash()` using bcrypt
- Vault files are served via a token-secured URL (`?action=public_vault&id=X&token=Y`), not accessible by guessing IDs
- The share token is derived from a per-install salt (based on the DB path), so tokens are not portable between installs
- **Use HTTPS** in production — consider Let's Encrypt / Certbot for a free SSL certificate
- For single-user personal use on a trusted network, the built-in PHP server is fine; for internet-facing deployments, use Apache or Nginx with proper SSL

---

## First-Time Setup Walkthrough

When you open the app for the first time:

1. **Account Credentials** — pick a username (min 3 chars) and password (min 6 chars)
2. **Personal Information** — name, date of birth, biological sex
3. **Physical Metrics** — weight (kg) and height (ft + in); BMI is calculated automatically
4. **Diagnosis Details** — diagnosis date, diabetes type, blood sugar at diagnosis, symptoms
5. **Doctor Info** — name and contact (optional but recommended)
6. **Glucose Targets** — default Low: 70 mg/dL, High: 180 mg/dL (ADA guidelines); adjust to match your doctor's advice

Click **Save & Get Started** — you land directly on your dashboard.

---

## Glucose Targets

Default thresholds follow ADA (American Diabetes Association) guidelines:

| Status | Range |
|---|---|
| Low | < 70 mg/dL |
| In Range | 70 – 180 mg/dL |
| High | > 180 mg/dL |

Change these anytime under **Settings → Glucose Targets**.

---

## Database

Glycotrack uses a single SQLite file at `data/diabetic.db`. Tables:

| Table | Purpose |
|---|---|
| `users` | Login credentials |
| `patient` | Health profile (one row, id=1) |
| `glucose_logs` | All glucose readings |
| `vault_files` | Uploaded report metadata |
| `journal` | Health journal entries |

**Backup:** just copy `data/diabetic.db` and `vault/` somewhere safe. That's your entire data.

---

## Upgrading

Glycotrack performs automatic schema migrations on startup (via `PRAGMA table_info` checks). To upgrade:

```bash
git pull origin main
```

Refresh the browser. No migration scripts to run manually.

---

## Contributing

Pull requests welcome. Keep the spirit of the project: **single file, zero build step, zero dependencies.**

```bash
git clone https://github.com/psvineet/glycotrack.git
cd glycotrack
php -S localhost:8080
# make changes to index.php
# test in browser
# open a PR
```

---

## License

MIT — use freely, modify freely, no warranty.

---

## Author

**Vineet Pratap Singh**
GitHub: [@psvineet](https://github.com/psvineet)
Email: [connect.vps@icloud.com](mailto:connect.vps@icloud.com)

---

*Glycotrack v1.1.2 — Built with ♥ for people managing diabetes, by someone who knows it matters.*
