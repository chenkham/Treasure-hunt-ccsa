# Organizer-managed Android setup

For current APK 2.10.0 instructions, use [MANAGED-KIOSK-SETUP.md](<C:/Users/chenk/Treasure hunt/md/MANAGED-KIOSK-SETUP.md>). The older notes below include superseded host-entry and offline-PIN menu behavior; the current version uses a fixed server and dashboard-authorized kiosk exit.

Full lock task mode requires a device policy controller/device owner, as documented by [Android Enterprise](https://developer.android.com/work/dpc/dedicated-devices/lock-task-mode). A normal APK cannot reliably block Home/Recents/system UI. Screen pinning can be escaped and is not used as a substitute.

Use organizer-owned phones or explicitly consented dedicated devices. Device-owner provisioning can require a clean device with no accounts; do not factory-reset participant devices without their separate, informed authorization. This project never wipes or auto-provisions a phone.

## Disposable emulator rehearsal

Install the debug APK only on the dedicated QA emulator, then explicitly provision its receiver:

```powershell
adb -s emulator-5580 install -r android/app/build/outputs/apk/debug/app-debug.apk
adb -s emulator-5580 shell dpm set-device-owner com.campushunt.app.debug/com.campushunt.app.HuntAdminReceiver
adb -s emulator-5580 shell pm grant com.campushunt.app.debug android.permission.CAMERA
adb -s emulator-5580 shell pm grant com.campushunt.app.debug android.permission.ACCESS_FINE_LOCATION
adb -s emulator-5580 shell pm grant com.campushunt.app.debug android.permission.ACCESS_COARSE_LOCATION
```

For real release provisioning the package is `com.campushunt.app`; use your device-management workflow or an explicitly authorized clean-device setup. Sign the release APK with your own key first. The debug build is not a competition build.

## Phone preparation

1. Install the signed release app; enter the HTTPS event server address.
2. Grant camera and precise location before kiosk entry. Choose a trusted basic keyboard; disable assistant/AI keyboard features through device management. Do not allow arbitrary third-party input/accessibility/overlay tools on competition devices.
3. Enroll the team with its PIN. The app creates a non-exportable Keystore signing key and binds that phone to the team. Complete at least two successful syncs so assignment acknowledgement reaches the dashboard.
4. Confirm actual managed kiosk state on the dashboard and inspect Home, Recents, notification shade, screenshots, overlays, power/assistant buttons and emergency access on each OEM model.
   The release build also restricts USB debugging and USB file transfer while kiosk is active. These restrictions are restored on authorized exit. They remain enabled in debug builds for disposable-emulator testing.
5. Preserve the per-device offline organizer exit PIN outside participant access. Rotated codes reach the phone only on synchronization; the downloaded old code remains valid while disconnected.
6. After each round/finish, synchronize evidence at a known connected area. For a dead phone, use an audited device handover; this revokes the old session and rotates the enrollment PIN. Confirmed progress remains, but unsynchronized evidence requires recovery/review.
7. Exit kiosk using organizer authorization after the event, export evidence if needed, and remove event access through your device-management procedure. The app removes only user restrictions it introduced; it does not remove another administrator's policy.

An emergency button can open the platform dialer without the organizer PIN. While it is open, the dialer package is temporarily allowlisted; on returning, the app restores its own allowlist. This is a deliberate safety exception. Power/hardware recovery cannot be made impossible and must not be the only emergency path.

While managed kiosk is active, the app registers itself as the persistent Home activity and relaunches after boot once Android permits it. Authorized exit clears that preference and the boot flag. A reboot still invalidates the cached timing anchor and requires a fresh server synchronization before competitive play resumes. Ordinary installations never change the launcher preference.

Verify real GPS in open sky and at difficult buildings. Mock locations are rejected. Do not enable mock GPS on competition devices. Preserve an independent marshal process for inaccessible checkpoints, GPS failure, device reboot, and disputed late evidence.
