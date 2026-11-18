package com.campushunt.app

import android.Manifest
import android.content.pm.PackageManager
import androidx.appcompat.app.AlertDialog
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.*
import org.robolectric.Shadows.shadowOf
import org.robolectric.annotation.*
import org.robolectric.shadows.ShadowDialog

@RunWith(RobolectricTestRunner::class)
@Config(sdk=[28,35])
@GraphicsMode(GraphicsMode.Mode.NATIVE)
class PermissionFlowTest {
    private fun result(activity: MainActivity,permissions: Array<String>,grants: IntArray) {
        // Use Android's dispatcher: directly calling the callback leaves its native
        // 'request in flight' flag set and incorrectly cancels the next request.
        val data=android.content.Intent()
            .putExtra(org.robolectric.util.ReflectionHelpers.getStaticField<String>(PackageManager::class.java,"EXTRA_REQUEST_PERMISSIONS_NAMES"),permissions)
            .putExtra(org.robolectric.util.ReflectionHelpers.getStaticField<String>(PackageManager::class.java,"EXTRA_REQUEST_PERMISSIONS_RESULTS"),grants)
        org.robolectric.util.ReflectionHelpers.callInstanceMethod<Any>(activity,"dispatchRequestPermissionsResult",
            org.robolectric.util.ReflectionHelpers.ClassParameter.from(Int::class.javaPrimitiveType!!,110),
            org.robolectric.util.ReflectionHelpers.ClassParameter.from(android.content.Intent::class.java,data))
    }
    @Test fun cameraGrantResumesExactlyOnce() {
        val app=RuntimeEnvironment.getApplication();app.deleteDatabase("hunt.db")
        shadowOf(app).denyPermissions(Manifest.permission.CAMERA)
        val controller=Robolectric.buildActivity(MainActivity::class.java).setup();val activity=controller.get()
        val permissions=arrayOf(Manifest.permission.CAMERA);var resumed=0
        assertFalse(activity.ensureAccess(permissions) { resumed++ })
        val request=shadowOf(activity).lastRequestedPermission
        assertEquals(110,request.requestCode);assertArrayEquals(permissions,request.requestedPermissions)
        shadowOf(app).grantPermissions(*permissions)
        result(activity,permissions,intArrayOf(PackageManager.PERMISSION_GRANTED))
        result(activity,permissions,intArrayOf(PackageManager.PERMISSION_GRANTED))
        assertEquals(1,resumed)
        assertTrue(activity.ensureAccess(permissions) { resumed++ });assertEquals(1,resumed)
        controller.pause().stop().destroy()
    }
    @Test fun approximateLocationCannotUnlockCheckpointScanningAndRetryWorks() {
        val app=RuntimeEnvironment.getApplication();app.deleteDatabase("hunt.db")
        val permissions=arrayOf(Manifest.permission.ACCESS_FINE_LOCATION,Manifest.permission.ACCESS_COARSE_LOCATION)
        shadowOf(app).denyPermissions(*permissions)
        val controller=Robolectric.buildActivity(MainActivity::class.java).setup();val activity=controller.get();var resumed=0
        assertFalse(activity.ensureAccess(permissions) { resumed++ })
        shadowOf(app).grantPermissions(Manifest.permission.ACCESS_COARSE_LOCATION)
        result(activity,permissions,intArrayOf(-1,0))
        assertEquals(0,resumed)
        val denial=ShadowDialog.getLatestDialog() as AlertDialog
        assertTrue(denial.isShowing);assertEquals("Try again",denial.getButton(AlertDialog.BUTTON_POSITIVE).text.toString())
        denial.getButton(AlertDialog.BUTTON_POSITIVE).performClick()
        shadowOf(android.os.Looper.getMainLooper()).idleFor(java.time.Duration.ofMillis(300))
        shadowOf(app).grantPermissions(*permissions)
        assertEquals(PackageManager.PERMISSION_GRANTED,activity.checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION))
        result(activity,permissions,intArrayOf(0,0))
        assertEquals(1,resumed)
        val store=HuntStore(activity);try { assertFalse(store.state().has("token")) } finally { store.close() }
        controller.pause().stop().destroy()
    }
}
