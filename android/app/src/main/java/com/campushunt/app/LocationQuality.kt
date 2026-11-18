package com.campushunt.app

import android.location.Location

/** Compare sensor times, so late network fixes cannot overwrite a recent precise fix. */
object LocationQuality {
    fun accept(next: Location, previous: Location?, nowNanos: Long): Boolean {
        val age=(nowNanos-next.elapsedRealtimeNanos)/1_000_000
        if(age !in 0..15000 || next.elapsedRealtimeNanos>nowNanos || !next.hasAccuracy() ||
            !next.latitude.isFinite() || !next.longitude.isFinite() ||
            next.latitude !in -90.0..90.0 || next.longitude !in -180.0..180.0 ||
            !next.accuracy.isFinite() || next.accuracy !in 0f..10000f) return false
        if(previous==null) return true
        if(next.elapsedRealtimeNanos<=previous.elapsedRealtimeNanos) return false
        val previousAge=(nowNanos-previous.elapsedRealtimeNanos)/1_000_000
        return previousAge !in 0..10000 || next.accuracy<=maxOf(previous.accuracy*2,previous.accuracy+15)
    }
}
