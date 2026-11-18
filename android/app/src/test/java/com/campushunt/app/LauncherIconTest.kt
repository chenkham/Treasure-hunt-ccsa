package com.campushunt.app

import android.graphics.drawable.AdaptiveIconDrawable
import android.graphics.drawable.BitmapDrawable
import android.graphics.drawable.LayerDrawable
import android.graphics.drawable.GradientDrawable
import android.graphics.Color
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.RuntimeEnvironment
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk=[28])
class LauncherIconTest {
 @Test fun fullColourCoverHasNoBlackBackgroundAndItsWordmarkFitsTheMask(){
  val app=RuntimeEnvironment.getApplication()
  val icon=app.applicationInfo.loadIcon(app.packageManager) as AdaptiveIconDrawable
  val foreground=icon.foreground as LayerDrawable
  val bitmap=(foreground.getDrawable(1) as BitmapDrawable).bitmap
  assertEquals(bitmap.width,bitmap.height);assertTrue(bitmap.width>0)
  assertEquals(1f,foreground.getLayerWidth(1).toFloat()/foreground.getLayerHeight(1),.01f)
  assertTrue(foreground.getLayerWidth(1)>=50*app.resources.displayMetrics.density)
  assertTrue(foreground.getLayerWidth(1)<=66*app.resources.displayMetrics.density)
  assertTrue(icon.background is BitmapDrawable)
  assertEquals("Treasure Hunt",app.applicationInfo.loadLabel(app.packageManager).toString())
 }
}
