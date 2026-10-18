# ZATCA Package — Server Requirements

This guide moved. The root README is the single source of truth for server setup, so this file no longer duplicates it:

- Basic PHP/Laravel/DB requirements → README [§4 Requirements](../README.md#4-requirements)
- Finding the right `WEB_USER`/`QUEUE_USER`/`CLI_USER`, storage permissions, installing the SDK and Java, Supervisor/systemd, and cron → README [§14 Server Setup & Production Deployment](../README.md#server-setup)

For production-specific concerns beyond first install — deployment stages, SDK/API version changes, certificate renewal — see **[ZATCA_WORKFLOWS.md](ZATCA_WORKFLOWS.md)**. For diagnosing a broken server (missing Java, wrong SDK path, permissions), see **[TROUBLESHOOTING.md](TROUBLESHOOTING.md#the-command-itself-fails)** or README [§14.9](../README.md#server-setup-troubleshooting).
