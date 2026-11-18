package com.campushunt.app

import java.security.MessageDigest
import java.text.Normalizer
import java.util.Locale
import kotlin.math.*

object Rules {
    fun normalize(value: String): String {
        val normalized=Normalizer.normalize(value,Normalizer.Form.NFKC).lowercase(Locale.ROOT)
        val clean=StringBuilder()
        normalized.codePoints().forEach { cp ->
            val type=Character.getType(cp)
            if(Character.isLetterOrDigit(cp) || type in listOf(Character.NON_SPACING_MARK.toInt(),Character.COMBINING_SPACING_MARK.toInt(),Character.ENCLOSING_MARK.toInt())) clean.appendCodePoint(cp)
            else clean.append(' ')
        }
        return clean.toString().trim().split(Regex("\\s+")).filter { it.isNotEmpty() }.joinToString(" ")
    }
    fun sha256(value: String): String = MessageDigest.getInstance("SHA-256")
        .digest(value.toByteArray(Charsets.UTF_8)).joinToString("") { "%02x".format(it) }
    fun answerMatches(answer: String, salt: String, validators: List<String>): Boolean =
        normalize(answer).isNotEmpty() && sha256("$salt:${normalize(answer)}") in validators
    fun meters(lat1: Double, lon1: Double, lat2: Double, lon2: Double): Double {
        val a = Math.toRadians(lat1); val b = Math.toRadians(lat2)
        val h = sin((b-a)/2).pow(2) + cos(a)*cos(b)*sin(Math.toRadians(lon2-lon1)/2).pow(2)
        return 6371000*2*atan2(sqrt(h),sqrt((1-h).coerceAtLeast(0.0)))
    }
    fun gpsError(lat: Double, lon: Double, accuracy: Double, ageMs: Long, mock: Boolean,
                 targetLat: Double, targetLon: Double, radius: Double): String? = when {
        mock -> "Turn off location-changing apps and ask an organizer for help."
        !lat.isFinite() || !lon.isFinite() || lat !in -90.0..90.0 || lon !in -180.0..180.0 -> "We are finding your location. Try again in a moment."
        !accuracy.isFinite() || accuracy !in 0.0..25.0 -> "Move into open sky so we can find your location."
        ageMs !in 0..15000 -> "We are finding your location. Try again in a moment."
        meters(lat,lon,targetLat,targetLon)>radius -> "You are outside this checkpoint's search area."
        else -> null
    }
    fun clockRemaining(remaining: Long, status: String, anchor: Long, elapsed: Long, sameBoot: Boolean): Long? {
        if (!sameBoot || elapsed<anchor) return null
        return if (status=="RUNNING") (remaining-(elapsed-anchor)).coerceAtLeast(0) else remaining
    }
    fun roundRemaining(duration: Long, startedAt: Long, pausedMs: Long, reference: Long, status: String): Long? {
        if(status=="DRAFT" || startedAt<=0) return null
        if(status=="ENDED") return 0
        if(duration==0L) return Long.MAX_VALUE
        return (duration-(reference-startedAt-pausedMs).coerceAtLeast(0)).coerceAtLeast(0)
    }
    fun pixel(lat: Double, lon: Double, a: DoubleArray, b: DoubleArray): Pair<Double,Double>? {
        val det=a[0]*b[1]-a[1]*b[0]
        if (abs(det)<1e-16) return null
        return Pair(((lat-a[2])*b[1]-(lon-b[2])*a[1])/det,
            (a[0]*(lon-b[2])-b[0]*(lat-a[2]))/det)
    }
}
