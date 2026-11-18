package com.campushunt.app
import org.junit.Assert.*
import org.junit.Test

class FeedbackPolicyTest {
    @Test fun firstCachedAndRegressedRevisionsNeverNotify() {
        val p=FeedbackPolicy()
        assertFalse(p.standing("event/1",12,4,true,0))
        assertFalse(p.standing("event/1",12,1,true,1000))
        assertFalse(p.standing("event/1",11,1,true,2000))
        assertTrue(p.standing("event/1",13,3,true,3000))
    }
    @Test fun declinesEliminationAndNewRoundDoNotCelebrate() {
        val p=FeedbackPolicy();p.standing("e/1",1,3,true,0)
        assertFalse(p.standing("e/1",2,4,true,1000))
        assertFalse(p.standing("e/1",3,1,false,2000))
        assertFalse(p.standing("e/2",4,1,true,3000))
    }
    @Test fun questAndRankBurstsAreCoalescedWithoutDelayedSpam() {
        val p=FeedbackPolicy();p.standing("e/1",1,5,true,0);p.quest(1000)
        assertFalse(p.standing("e/1",2,4,true,2000))
        assertFalse(p.standing("e/1",2,4,true,32000))
        assertTrue(p.standing("e/1",3,3,true,33000))
        assertFalse(p.standing("e/1",4,2,true,34000))
        assertTrue(p.standing("e/1",5,1,true,64000))
    }
    @Test fun missingRanksAndResumeResetEstablishNewBaseline() {
        val p=FeedbackPolicy();p.standing("e/1",1,4,true,0)
        assertFalse(p.standing("e/1",2,null,true,1000))
        assertFalse(p.standing("e/1",3,2,true,2000))
        p.reset();assertFalse(p.standing("e/1",4,1,true,40000))
    }
}
