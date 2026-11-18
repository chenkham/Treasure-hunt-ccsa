package com.campushunt.app
import android.view.WindowManager
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.Robolectric
import org.robolectric.RobolectricTestRunner
import org.robolectric.RuntimeEnvironment
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode
import org.robolectric.shadows.ShadowDialog
import android.widget.EditText

@RunWith(RobolectricTestRunner::class)
@Config(sdk=[28])
@GraphicsMode(GraphicsMode.Mode.LEGACY)
class SecureWindowsTest {
 @Test fun loginHasTwoFieldsAndWindowsRetainCaptureProtection(){
  val app=RuntimeEnvironment.getApplication()
  app.deleteDatabase("hunt.db")
  // Robolectric does not run the manifest's AndroidX Startup provider.
  if(!BuildConfig.UI_PREVIEW) {
   try { androidx.work.WorkManager.getInstance(app) }
   catch(_: IllegalStateException) { androidx.work.WorkManager.initialize(app,androidx.work.Configuration.Builder().build()) }
  }
  val controller=Robolectric.buildActivity(MainActivity::class.java).setup();val activity=controller.get()
  try {
   fun entries(view: android.view.View): List<EditText> = when(view){
    is EditText->listOf(view)
    is android.view.ViewGroup->(0 until view.childCount).flatMap { entries(view.getChildAt(it)) }
    else->emptyList()
   }
   assertEquals(2,entries(activity.window.decorView).size)
   val secure=activity.window.attributes.flags and WindowManager.LayoutParams.FLAG_SECURE!=0
   assertEquals(!BuildConfig.UI_PREVIEW,secure)
   val dialog=HuntDialogBuilder(activity).setMessage("Organizer check").create()
   assertEquals(!BuildConfig.UI_PREVIEW,dialog.window!!.attributes.flags and WindowManager.LayoutParams.FLAG_SECURE!=0)
   LocalKeypad.show(activity,EditText(activity),true)
   val keypad=ShadowDialog.getLatestDialog()
   assertNotNull(keypad)
   assertEquals(!BuildConfig.UI_PREVIEW,keypad.window!!.attributes.flags and WindowManager.LayoutParams.FLAG_SECURE!=0)
   keypad.dismiss()
  }finally{controller.pause().stop().destroy()}
 }
}
