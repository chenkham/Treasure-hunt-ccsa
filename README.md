# Treasure Hunt — Android app and organizer website

This repository contains the Treasure Hunt Android app source, PHP/MySQL organizer dashboard and API, plus the signed Android APK for installation.

## Contents

- [`android/`](android/) — complete Android Studio project and Kotlin app source.
- [`web/`](web/) — PHP API, organizer dashboard, database schema, and required Composer libraries.
- [`releases/CampusHunt-2.15.0.apk`](releases/CampusHunt-2.15.0.apk) — signed APK, ready to install on Android.
- [`web/HOSTINGER_SETUP.md`](web/HOSTINGER_SETUP.md) — server setup and deployment notes.

The app has an offline campus vector map, GPS display, QR scanning, team login, local progress queue, and live server synchronization. The PHP dashboard manages teams, riddles, event controls, QR print sheets, and the leaderboard.

## Build the Android app

Open `android/` in Android Studio, or build from a shell with JDK 21 and the Android SDK installed:

```sh
cd android
./gradlew :app:assembleRelease
```

On Windows, use `gradlew.bat`. The source repository intentionally does not contain the private APK signing key. A locally built APK therefore needs your own signing setup; the included release APK is already signed for installation.

## Set up the PHP website

1. Create a separate MySQL database and database user.
2. Copy `web/config.example.php` to `web/config.php` and fill in the database credentials on the server.
3. Follow [`web/HOSTINGER_SETUP.md`](web/HOSTINGER_SETUP.md) to upload the PHP files and initialize the database.
4. Keep `config.php` private and outside Git. Do not commit database, SMTP, administrator, or signing credentials.

The repository's `web/database/seed.json` is an empty install template. The private event riddles and answer locations are intentionally not published in this repository; add them through the organizer dashboard after setup, or keep any event-specific seed file in private storage.

The dashboard requires PHP and MySQL. The app uses the API address configured in its Android source; changing that address and rebuilding is only necessary when moving to a different server.

## Data, source terms, and release notes

The Android map and web atlas are based on geographic source data and include source attribution and licence notices with their assets. Preserve those notices when reusing the map. Public location pins and the campus boundary are approximate and need local verification before navigation or safety-critical use.

This repository does not contain production database credentials, private signing keys, administrator passwords, private team login records, or deployment configuration.
