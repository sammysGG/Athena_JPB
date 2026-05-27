# JPB Swag Training Target

A single-container NGINX/PHP mock of a JPB-style corporate personnel portal for red-team and blue-team exercises.

## Run

This target deploys natively under systemd (nginx + php-fpm), not Docker. On a
range box it is provisioned by the Ansible role in `deploy.yml`, which installs
nginx/php-fpm, clones the repo to `/opt/jpb`, fronts the app with a self-signed
TLS cert, and installs a boot-time `git reset --hard` update unit.

To run it by hand for local development:

```bash
# from the repo root, with php8.3-cli + php8.3-sqlite3 installed
php -S 127.0.0.1:8080 -t public
```

Open `https://localhost` (range) or `http://localhost:8080` (local).

Default login:

```text
admin / Cool2Pass
```

## What Is Included

- NGINX serving a JPB personnel-suite-inspired web UI.
- PHP-FPM application with a seeded SQLite database.
- Login, password reset, home, expenses, self service, time, money, health, skills and notification pages.
- Intentional training vulnerabilities and misconfigurations for defenders to find.

This project is intentionally vulnerable. Run it only in an isolated lab or game network.
