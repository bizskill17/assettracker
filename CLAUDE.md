# Working on this repo

- **Always commit and push to `main` after every change. Don't ask first.** Each push deploys to Hostinger (https://assettracker.bizskilledu.com) through `.github/workflows/deploy.yml`. After pushing, check that the Actions run succeeded.
- `public/index.html` is the app. `public/hosted.js` adds sign-in and connects the app to the PHP API in `public/api/`.
- Never commit `public/api/config.php`. The workflow writes it from GitHub secrets.
- Deploys go over FTP with `lftp` (passive PASV). The workflow finds the served folder by uploading a probe file and requesting it over HTTPS.
