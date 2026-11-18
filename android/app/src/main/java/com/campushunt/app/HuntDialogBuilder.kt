package com.campushunt.app
import android.content.Context
import android.os.Build
import android.view.WindowManager
import androidx.appcompat.app.AlertDialog
import com.google.android.material.dialog.MaterialAlertDialogBuilder

/** Keep organizer dialogs and scanner windows protected as well as the activity. */
class HuntDialogBuilder(context: Context): MaterialAlertDialogBuilder(context) {
 override fun create(): AlertDialog=super.create().also { dialog->
  if(!BuildConfig.UI_PREVIEW){dialog.window?.addFlags(WindowManager.LayoutParams.FLAG_SECURE);if(Build.VERSION.SDK_INT>=31)dialog.window?.setHideOverlayWindows(true)}
 }
}
