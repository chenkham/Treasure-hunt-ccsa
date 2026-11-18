# Anazori Treasure Hunt — 27 October 2026

## Upload

Extract Anazori-27-Oct-2026-Update.zip into the existing public_html directory. Its treasure-hunt folder merges into public_html/treasure-hunt. Use the existing hunt-private/config.php beside public_html. The database connection and administrator login remain in that private configuration.

Open https://anazori.ccsdu.in/treasure-hunt/dashboard/ and sign in with your existing administrator credentials. A new installation initializes on first successful login. An unstarted event automatically imports the 40 confirmed workbook riddles into MySQL. Repeated logins do not overwrite edits or regenerate QR codes. An event with existing scan evidence or a started round is preserved; a message explains when import cannot proceed.

## Organizer controls

- Riddles: edit the clue, optional hint, hint visibility, public title and private placement. All 40 are unlabelled; there are no difficulty categories. The source's explicit answer line was removed from the participant clue. Location answers stay private.
- Teams: create team login names and passwords, or import the roster. Assign one Android phone per team. Save the offline organizer exit PINs privately.
- Event settings: choose time limits and qualifying-team caps. Rounds contain 3, 7 and 2 riddles. Shuffle & publish assigns Round 1. Reshuffle before start regenerates assignments and requires team phones to reconnect. Assignments lock when play starts.
- Round controls: start, pause, resume and end the round. Prepare later rounds after the previous round ends. Every team gets fresh riddles; its 12-riddle route never repeats a station. Starting stations and station usage are balanced on the server.
- Clue text, placement and hints remain editable during play. Scan revisions and station nonces remain stable, so existing printed QR codes and queued scans survive these text edits. Additions, removals and round rules are locked after publication to protect assignments and results.
- Organizer sheets / PDF includes the question, private placement and optional hint. Station sheets contain only the signed QR and station identifier. Generate the real print sheets from the hosted dashboard so they use the hosted database's signing key.
- Dashboard accounts: owners manage staff accounts and email delivery. Organizers manage the hunt. Volunteers see live progress and the team map without clue answers, editing, print/export, round controls or account management. Password and role changes revoke old sessions.

## Participant APK

Distribute CampusHunt.apk version 2.6.1 (build 10). Its production server address is prefilled. Riddles are not bundled in the APK: the server reads MySQL and sends the team's signed assignment. Future riddle edits require no new APK. Released hints appear in the quest log; downloaded clues, hints and queued scans remain available offline.

Phones synchronize on connection. No-network progress is provisional. Official qualification and the winner use server receipt; teams must reconnect before the round closes. Offline GPS cannot reach the organizer live; the map shows last contact. Three wrong-sequence scans start the existing 15-minute heart recovery.

## Verification

The release checks exercise the real PHP API classes against a disposable local MySQL database, including 1,000-team allocation in each round, signed QR scans, replay handling, cooldown, final winner, hints after publication, stable station nonces and staff restrictions. Android release build and compatible unit checks pass. Live hosting, the event phones' camera/GPS and email inbox delivery still require the upload and a phone rehearsal. No live database was modified by local packaging.

PHP must be patched. Hostinger managed builds can keep an older version number; the app checks the observed build date against Hostinger's documented patch date. References: https://www.php.net/releases/8_4_27.php and https://www.hostinger.com/blog/protecting-php2shell/
