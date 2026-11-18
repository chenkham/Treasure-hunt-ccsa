# Hosting and event operation

For the current PHP/MySQL Hostinger deployment, use [HOSTINGER_SETUP.md](../php-server/HOSTINGER_SETUP.md). The Python/SQLite hosting notes below are a reference option. Use one backend authority for the event.

Run one API worker per event database. SQLite WAL and immediate write transactions serialize progress/score updates for the intended 40–70-team event. Do not put this database on a network share or start unrelated multi-host writers. A larger multi-host deployment needs a PostgreSQL persistence adapter and load testing; changing only a connection string is insufficient.

Use HTTPS with a trusted certificate on an organizer-controlled host. The release APK rejects HTTP. For a local-only network, provision a trusted TLS setup; do not bypass certificate validation or use a public proxy for participant location. Local Wi-Fi coverage must be surveyed rather than assumed campus-wide.

Example host reverse proxy:

```caddy
hunt.example.edu {
    reverse_proxy 127.0.0.1:8100
}
```

Replace the hostname with your real domain. Run `uvicorn server.app:app --host 127.0.0.1 --port 8100 --workers 1` behind the proxy. Initialize the administrator through `python -m server.manage init` using the same `HUNT_DATA_DIR`. No CORS access is needed for the same-origin dashboard or native client.

Back up the runtime database and organizer private key together using SQLite's backup API or while the service is stopped. Protect backups and runtime files: they contain private answer keys, precise team locations, audit evidence and session metadata. Never commit them. Keep locations private to authorized organizers; public standings omit coordinates and credentials. Archive/delete event records according to the event's announced retention policy after disputes are resolved.

The server keeps accepted actions, signed evidence and organizer changes. A network interruption cannot guarantee live command delivery or live standings. Organizer controls must be accompanied by a physical/marshal communication plan where event-wide pause or emergency information must reach disconnected teams.

Run a rehearsal with the final signed release APK and real device-owner setup. Check camera glare, QR print quality, battery, reboot/time recovery, low-signal paths, GPS accuracy, georeferenced map fit, accessible walking paths and the connected finish zone. Synthetic tests are not campus calibration or a real 70-phone load test.
