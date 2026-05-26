# JPB Swag Training Target

A single-container NGINX/PHP mock of a JPB-style corporate personnel portal for red-team and blue-team exercises.

## Run

```powershell
docker compose up --build
```

Open `http://localhost:8080`.

Default login:

```text
admin / password
```

## What Is Included

- NGINX serving a JPB personnel-suite-inspired web UI.
- PHP-FPM application with a seeded SQLite database.
- Login, password reset, home, expenses, self service, time, money, health, skills and notification pages.
- Intentional training vulnerabilities and misconfigurations for defenders to find.

This project is intentionally vulnerable. Run it only in an isolated lab or game network.
