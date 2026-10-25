# FurimaDeck CI/CD

FurimaDeck production deployment is manual only. Do not use the legacy FURUPRO deployment settings or workflow.

## CI

The `CI` workflow runs on pull requests, pushes to `main` and `codex/**`, and manual dispatch. The separate `Security` workflow scans pushes and pull requests.

- CI installs PHP and Node dependencies, prepares an isolated test environment, runs browser E2E and PHP tests, builds the frontend, and audits Composer and npm dependencies.
- The MariaDB CSV gate runs database migration and CSV tests against an isolated MariaDB service.
- Security runs Semgrep and secret scanning; pull requests also run dependency review.

## CD

`Deploy FurimaDeck` is triggered manually from `main`; it does not deploy automatically after CI or Security. Before it opens an SSH connection, it requires the latest `CI` and `Security` push runs for the exact dispatch commit to have completed successfully. If `main` advances after dispatch, the remote deployment stops and must be dispatched again for the new commit.

Production deployment is intentionally configured with the GitHub `production` environment. Do not commit server credentials or production `.env` values. Configure required reviewers on that environment where available, and do not configure the legacy `DEPLOY_*` settings for FurimaDeck.

Required `production` environment secrets:

- `FURIMADECK_DEPLOY_HOST`: SSH host name or IP address.
- `FURIMADECK_DEPLOY_USER`: SSH user name.
- `FURIMADECK_DEPLOY_SSH_PRIVATE_KEY`: Private key for the deployment user.
- `FURIMADECK_DEPLOY_SSH_KNOWN_HOSTS`: Expected SSH host key entry.

Required `production` environment variable:

- `FURIMADECK_DEPLOY_PATH`: Must be exactly `/var/www/furimadeck`; the workflow refuses any other path to prevent accidental deployment to legacy or unintended directories.

Optional `production` environment variable:

- `FURIMADECK_DEPLOY_PORT`: SSH port; defaults to `22`.

Before the first deployment, a human must verify the exact target path, the dedicated FurimaDeck `.env` and database, a recent recoverable backup, HTTPS/Apache configuration, and the deployment user's restricted sudo permissions. The deployment workflow does not create or validate a backup. It runs migrations and seeders only after a reviewed commit passes CI and Security. Never point it at the old FURUPRO database or server path.

The deployment workflow is for an already provisioned application checkout; it is not a first-install bootstrap. Before dispatching it, the checkout, production `.env`, database schema, and systemd units must already exist. The workflow checks that `furimadeck-queue.service`, `furimadeck-scheduler.timer`, and `apache2` are enabled and active before fetching or merging code or running migrations. For initial setup, provision the application and database first, apply the reviewed migrations, then have a root operator install and enable the units with `deploy/scripts/install-startup-services.sh`. After a workflow deployment, the queue worker and scheduler timer are restarted and all three services are checked again; a failed check fails the deployment.

For the database queue worker, keep `DB_QUEUE_RETRY_AFTER` longer than the worker's `--timeout`. The shipped values are 120 and 90 seconds respectively; each job-level timeout must also be below the retry window. Do not set these values equal or reverse their order. After changing the production environment, rebuild Laravel's config cache and restart the worker.

Each manual workflow dispatch requires `production_backup_reference`, a non-secret identifier for the backup the operator verified exists. This input records an operator attestation only; it does not inspect the backup or prove that a restore succeeded. Keep required reviewers enabled for the GitHub `production` environment.

The deployment workflow checks PHP 8.4.1 or newer, a clean checkout, and absence of `public/hot` before installing dependencies. It refuses the known legacy path `/var/www/furugi`; this path check is a guardrail, not proof that any other path is a correct FurimaDeck target.

The deployment user must be allowed to run the required commands as `www-data`, change ownership of the existing `vendor`, `node_modules`, and `public/build` directories to `www-data`, and reload Apache. The workflow changes ownership only for those generated directories to prevent the Composer/npm/Vite permission errors previously seen on the VPS; it refuses symlinks and does not recursively change ownership outside those directories. Prefer restricted passwordless sudo rules for only those commands. Review the GitHub Actions run and environment approval before any production deployment.
