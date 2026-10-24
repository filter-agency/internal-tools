# Filter Internal Tools — Runbook

## Environments

| Environment | URL | Server |
|---|---|---|
| Production | https://internal.filter.agency | DigitalOcean droplet (2 vCPU / 4GB / 120GB) `159.65.19.139` |

Manual server setup — no Laravel Forge. Deploys are handled by GitHub Actions.

---

## Deploy

Deploys trigger automatically on push to `main` via GitHub Actions (`.github/workflows/deploy.yml`).

The deploy workflow SSHes into the droplet as the `deploy` user and runs:

```bash
cd /var/www/internal.filter.agency
trap 'php artisan up || true' EXIT
php artisan down --retry=60 || true
git pull origin main
composer install --no-dev --no-interaction --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

The app is put into maintenance mode for the duration of the deploy (`artisan down`/`up`). This isn't just for show — without it, a live request landing mid-deploy can make PHP-FPM (running as `www-data`) recompile a view itself, writing it with permissions the `deploy` user can't later overwrite, which fails `artisan view:cache` with a permission error. The `trap` guarantees `artisan up` runs even if a step fails, so a broken deploy can't leave the site stuck in maintenance mode.

Queue restart signals and the worker's between-jobs liveness checks use the
dedicated `QUEUE_WORKER_CACHE_STORE` (`file` on this single-server deployment).
The normal application cache remains database-backed. This separation means a
brief MySQL outage can pause database jobs without also crashing the worker's
restart check; Supervisor can then keep the worker alive until MySQL recovers.

To trigger a manual deploy, push any commit to `main` or run an empty commit:

```bash
git commit --allow-empty -m "chore: trigger deploy"
git push origin main
```

Deploy logs are visible in GitHub → Actions tab.

---

## Rollback

If a deploy breaks production, SSH in and revert manually:

```bash
ssh deploy@159.65.19.139
cd /var/www/internal.filter.agency
trap 'php artisan up || true' EXIT
php artisan down --retry=60 || true
git log --oneline -10          # find the last good commit hash
git checkout <commit-hash>
composer install --no-dev --no-interaction --optimize-autoloader
npm ci && npm run build
php artisan migrate --force    # only if rollback includes a migration reversal
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

If a migration must be reversed: `php artisan migrate:rollback` — confirm with Paul before running in production.

---

## Restore from Backup

Backups are taken via daily DigitalOcean droplet snapshots. These cover the full server state: OS, MySQL data directory, app files, and `.env`.

To restore from a snapshot, use the DigitalOcean console to restore the droplet to the desired snapshot. Note: this restores the entire server to that point in time.

### Restore database only (from mysqldump)

If you need to restore just the database without a full droplet restore:

```bash
# 1. Take a manual dump first (safety net)
mysqldump -u internal_tools -p internal_tools > /tmp/pre-restore-backup.sql

# 2. Restore from a dump file
mysql -u internal_tools -p internal_tools < /path/to/dump.sql

# 3. Clear caches
php artisan cache:clear
```

### Restore application files

Application files are in git — re-deploy from the correct commit rather than restoring from backup.

---

## Server Details

| Component | Details |
|---|---|
| OS | Ubuntu 22.04 LTS |
| Web server | Nginx |
| PHP | 8.2 + PHP-FPM (`/run/php/php8.2-fpm.sock`) |
| Database | MySQL 8.0, database: `internal_tools`, user: `internal_tools` |
| App directory | `/var/www/internal.filter.agency` |
| Deploy user | `deploy` |
| SSL | Let's Encrypt (Certbot, auto-renewing via systemd timer) |

`.env` lives at `/var/www/internal.filter.agency/.env` — never committed to git. Google OAuth credentials and DB password are stored there.

---

## Common Artisan commands

```bash
# Run migrations
php artisan migrate

# Import Harvest CSV
php artisan harvest:import /path/to/detailed-time.csv

# Reconcile import against Harvest CSV
php artisan harvest:reconcile /path/to/detailed-time.csv

# Import start dates, leave dates and person type from the HR Employee Matrix (see below)
php artisan users:import-employee-matrix /tmp/employee-matrix.xlsx --dry-run

# Seed the database (local only)
php artisan db:seed

# Clear all caches
php artisan optimize:clear
```

### Import start and leave dates (HR Employee Matrix)

`users:import-employee-matrix` sets each person's start date, leave date and person type (employee or contractor) from HR's "Employee Matrix" spreadsheet. The file holds personal data, so keep it outside the web root and delete it afterwards.

1. Copy the HR xlsx to the server outside the web root, for example `scp employee-matrix.xlsx deploy@<server>:/tmp/`.
2. From the app directory, run it with `--dry-run` and review the output: `php artisan users:import-employee-matrix /tmp/employee-matrix.xlsx --dry-run`. Nothing is written. The table shows `new (was old)` for every field that would change. Read each section underneath: unmatched names, people listed more than once, leave dates before start dates, leave dates that differ from the matrix, skipped rows, and approximated leave dates.
3. Run it for real, without `--dry-run`.
4. Delete the file: `rm /tmp/employee-matrix.xlsx`.

Notes:

- The matrix only marks a leave month, so a leave date is filled in (as the last day of that month) only when the person has none. Saved leave dates are never overwritten. Correct the approximated dates listed in the output in Admin › Users.
- Start date and person type always follow the sheet.
- People are matched on full name, ignoring case. The command refuses a sheet whose month columns don't start at Feb 2024.

---

## Contacts

| Role | Person | Contact |
|---|---|---|
| Product owner | Paul Halfpenny | paul@filteragency.com |
| Server SSH key | deploy user | `~/.ssh/internal_tools_actions_deploy` (local Mac) |
