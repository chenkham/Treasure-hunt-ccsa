package com.campushunt.app

import android.view.View
import android.view.ViewGroup
import android.widget.EditText
import com.google.android.material.button.MaterialButton
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.Robolectric
import org.robolectric.RobolectricTestRunner
import org.robolectric.RuntimeEnvironment
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

@RunWith(RobolectricTestRunner::class)
@Config(sdk=[35])
@GraphicsMode(GraphicsMode.Mode.NATIVE)
class EnrollmentBoundaryTest {
    private fun views(v: View): List<View> = listOf(v)+if(v is ViewGroup) (0 until v.childCount).flatMap { views(v.getChildAt(it)) } else emptyList()
    @Test fun normalAppDoesNotGrantAnArbitraryLogin() {
        assertFalse(BuildConfig.UI_PREVIEW)
        // Android launches Startup providers on a device; initialize the dependency in this JVM harness.
        androidx.work.WorkManager.initialize(RuntimeEnvironment.getApplication(),androidx.work.Configuration.Builder().build())
        val controller=Robolectric.buildActivity(MainActivity::class.java).setup();val activity=controller.get()
        val edits=views(activity.window.decorView).filterIsInstance<EditText>();assertEquals(3,edits.size)
        edits[0].setText("arbitrary");edits[1].setText("not-a-valid-team-pin");edits[2].setText("http://127.0.0.1:9")
        views(activity.window.decorView).filterIsInstance<MaterialButton>().single { it.text.toString()=="Start hunt" }.performClick()
        val request=org.robolectric.Shadows.shadowOf(activity).lastRequestedPermission
        assertNotNull(request)
        assertTrue(request.requestedPermissions.contains(android.Manifest.permission.CAMERA))
        assertTrue(request.requestedPermissions.contains(android.Manifest.permission.ACCESS_FINE_LOCATION))
        assertTrue(request.requestedPermissions.contains(android.Manifest.permission.ACCESS_COARSE_LOCATION))
        val store=HuntStore(activity);assertFalse(store.state().has("token"));assertNull(store.packageData());store.close()
        controller.pause().stop().destroy()
    }
}
