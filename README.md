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

### 2. Create an FTP account for deployments (hPanel)
1. Go to **Files → FTP Accounts → Create a New FTP Account**.
2. Set **Directory** to the site's `public_html`. Choose a username and a strong password.
3. Note the **FTP host**, shown under FTP Access (for example `ftp.yourdomain.com` or an IP address).

> If you use the main FTP account instead, its root is above `public_html`. In that case add a repository **variable** `FTP_SERVER_DIR` with the path to the site folder, for example `domains/yourdomain.com/public_html/` (note the trailing slash).

### 3. Add GitHub secrets
In this repo, go to **Settings → Secrets and variables → Actions → New repository secret** and add:

| Secret | Value |
|---|---|
| `FTP_SERVER` | FTP host from step 2 |
| `FTP_USERNAME` | FTP username from step 2 |
| `FTP_PASSWORD` | FTP password from step 2 |
| `DB_HOST` | `localhost` (Hostinger's usual value) |
| `DB_NAME` | database name from step 1 |
| `DB_USER` | database user from step 1 |
| `DB_PASS` | database password from step 1 |

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
