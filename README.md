# Hostel Asset Movement Tracker

Tracks every asset across hostel blocks: where it is, its status, and every movement between rooms, blocks, the store and the repair workshop. It includes masters for Rooms, Users, Item types, Vendors, Categories and Movement reasons.

It runs on Hostinger shared hosting (PHP 8 + MySQL). Every push to `main` deploys automatically through GitHub Actions.

## What's in the repo

```
public/                 ← uploaded to your site's public_html
  index.html            ← the app (desktop left menu, mobile bottom bar)
  hosted.js             ← sign-in screen + connects the app to the API
  api/index.php         ← JSON API (login, read, save, delete)
  api/install.php       ← creates the MySQL tables on first run
  api/.htaccess         ← blocks direct access to config/install files
.github/workflows/deploy.yml  ← GitHub Actions: deploy to Hostinger over FTP
```

## One-time setup

### 1. Create the database (Hostinger hPanel)
1. Go to **Websites → Manage → Databases → Management**.
2. Create a database and a database user, and note all three: database name, user and password. On Hostinger they look like `u123456789_assets`.
3. You don't need to run any SQL. The tables are created automatically the first time the app opens.

### 2. Turn on SSH (hPanel)
1. Go to **Advanced → SSH Access** and click **Enable**.
2. Note the **IP**, **Port** (usually `65002`) and **Username** (like `u380752258`). Set an SSH password on the same page.

> Deploys use SSH + rsync. Plain FTP from GitHub to Hostinger tends to be cut off (`ECONNRESET`), so FTP is kept only as a fallback.

### 3. Add GitHub secrets
In this repo, go to **Settings → Secrets and variables → Actions → New repository secret** and add:

| Secret | Value |
|---|---|
| `SSH_HOST` | SSH IP from step 2 |
| `SSH_PORT` | `65002` |
| `SSH_USERNAME` | SSH username from step 2 |
| `SSH_PASSWORD` | SSH password from step 2 |
| `DB_HOST` | `localhost` |
| `DB_NAME` | database name from step 1 |
| `DB_USER` | database user from step 1 |
| `DB_PASS` | database password from step 1 |

Files go to `domains/assettracker.bizskilledu.com/public_html/`. For another domain, add a repository **variable** `DEPLOY_PATH` with that site's path.

### 4. Deploy
- Go to **Actions → Deploy to Hostinger → Run workflow**, or push any change to `main`.
- When the run turns green, open your domain.
- In hPanel, turn on **SSL** for the domain so sign-in happens over HTTPS.

### 5. First sign-in
- The first visit shows **Create the admin account**. Enter your name, email and a password; this becomes the Admin.
- Add your blocks under **Blocks** (give each a short code such as A or B), then rooms under **Masters → Rooms** (use **Bulk add rooms** for whole floors).
- Add people under **Masters → Users**. Give each an email and a **Login password** so they can sign in.

## Roles

| Role | Can do |
|---|---|
| **Admin** | Everything, including users, blocks and all masters |
| **Viewer** | Look only, no changes |
| Any other role (Warden, Maintenance, Store keeper…) | Add and edit assets and log movements; masters are read-only |

## Notes
- Item types, categories and movement reasons are loaded as defaults on first run, and you can edit them.
- The database password never goes into the repo. The workflow writes `public/api/config.php` from the secrets during each deploy.
- **Export CSV** in the Movement log downloads the filtered log for Excel.
- To run locally, copy `public/api/config.sample.php` to `config.php`, fill it in, then run `php -S localhost:8000 -t public`.
