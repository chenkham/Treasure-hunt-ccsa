package com.campushunt.app
import org.junit.Assert.*
import org.junit.Test

class RulesTest {
    @Test fun exactAliasesOnly() {
        val salt="test"; val validators=listOf(Rules.sha256("test:central library"))
        assertTrue(Rules.answerMatches(" Central--Library ",salt,validators))
        assertFalse(Rules.answerMatches("lib",salt,validators))
        assertFalse(Rules.answerMatches("",salt,validators))
    }
    @Test fun unicodeSurvives() { assertEquals("অসম",Rules.normalize("অসম")); assertEquals("বিশ্ববিদ্যালয়",Rules.normalize("বিশ্ববিদ্যালয়")) }
    @Test fun distance() { assertEquals(111.19,Rules.meters(0.0,0.0,0.001,0.0),0.1) }
    @Test fun gpsRejectsBadEvidence() {
        assertNotNull(Rules.gpsError(27.0,94.0,100.0,0,false,27.0,94.0,65.0))
        assertNotNull(Rules.gpsError(27.0,94.0,5.0,16000,false,27.0,94.0,65.0))
        assertNotNull(Rules.gpsError(27.0,94.0,5.0,0,true,27.0,94.0,65.0))
        assertNotNull(Rules.gpsError(27.1,94.0,5.0,0,false,27.0,94.0,65.0))
        assertNull(Rules.gpsError(27.0,94.0,5.0,0,false,27.0,94.0,65.0))
    }
    @Test fun clockIgnoresWallTimeAndRejectsReboot() {
        assertEquals(9000L,Rules.clockRemaining(10000,"RUNNING",500,1500,true))
        assertEquals(10000L,Rules.clockRemaining(10000,"PAUSED",500,1500,true))
        assertEquals(0L,Rules.clockRemaining(10000,"RUNNING",500,20000,true))
        assertNull(Rules.clockRemaining(10000,"RUNNING",500,1500,false))
    }
    @Test fun mapProjection() {
        val p=Rules.pixel(27.002,94.003,doubleArrayOf(0.000001,0.0,27.0),doubleArrayOf(0.0,0.000001,94.0))!!
        assertEquals(2000.0,p.first,.01); assertEquals(3000.0,p.second,.01)
        assertNull(Rules.pixel(0.0,0.0,doubleArrayOf(1.0,1.0,0.0),doubleArrayOf(2.0,2.0,0.0)))
    }
}
