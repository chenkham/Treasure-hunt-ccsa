package com.campushunt.app

import android.location.Location
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk=[28])
class LocationQualityTest {
    private fun fix(ms: Long, metres: Float)=Location("test").apply {
        latitude=27.448067;longitude=94.8953513;accuracy=metres;elapsedRealtimeNanos=ms*1_000_000
    }
    @Test fun rejectsLateWeakAndInvalidSensorFixes() {
        val gps=fix(20000,5f)
        assertFalse(LocationQuality.accept(fix(19000,3f),gps,21000_000_000))
        assertFalse(LocationQuality.accept(fix(21000,120f),gps,22000_000_000))
        assertTrue(LocationQuality.accept(fix(23000,8f),gps,24000_000_000))
        assertTrue(LocationQuality.accept(fix(32000,120f),gps,33000_000_000))
        assertFalse(LocationQuality.accept(fix(1000,5f),null,20000_000_000))
        assertFalse(LocationQuality.accept(fix(21000,5f),null,20000_000_000))
        assertFalse(LocationQuality.accept(fix(20000,Float.NaN),null,20000_000_000))
        assertFalse(LocationQuality.accept(fix(20000,5f).apply{latitude=Double.NaN},null,20000_000_000))
    }
}
