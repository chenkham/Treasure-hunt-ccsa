package com.campushunt.app

import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk=[28])
class RoundResultTest {
    private fun state(round: Int,local: Int,official: Int,place: Int=0)=JSONObject()
        .put("local_progress",JSONObject().put(round.toString(),JSONObject().put("completed",local)))
        .put("official_progress",JSONObject().put(round.toString(),JSONObject().put("completed",official).put("podium_place",place)))
    @Test fun qualificationWaitsForNextRoundAndNeverClaimsThePrize(){
        for((round,total) in listOf(1 to 3,2 to 7)){
            val result=RoundResult.copy(state(round,total,total),round,total)!!
            assertEquals(0,result.place);assertTrue(result.detail.contains("Wait for Round ${round+1}"));assertFalse(result.title.contains("WON"))
        }
    }
    @Test fun offlineFinalCompletionWaitsForServerConfirmation(){
        val result=RoundResult.copy(state(3,2,1),3,2)!!
        assertEquals("FINISH SAVED",result.title);assertEquals(0,result.place);assertTrue(result.detail.contains("Connect to the internet"))
    }
    @Test fun confirmedPodiumHasThreeDistinctRewardMessages(){
        for(place in 1..3){val result=RoundResult.copy(state(3,2,2,place),3,2)!!
            assertEquals(place,result.place);assertTrue(result.detail.contains("claim your reward"));assertEquals("place-$place",result.key)
        }
    }
    @Test fun closureAndEliminationDoNotShowAFalseWinner(){
        val ended=state(3,1,1).put("eliminated",true).put("rounds",JSONObject().put("3",JSONObject().put("end_reason","PODIUM_COMPLETE")))
        assertEquals("HUNT CLOSED",RoundResult.copy(ended,3,2)!!.title)
        ended.getJSONObject("rounds").getJSONObject("3").put("end_reason","ORGANIZER_ENDED")
        assertEquals("ELIMINATED",RoundResult.copy(ended,3,2)!!.title)
        assertNull(RoundResult.copy(state(1,1,1),1,3))
    }
    @Test fun rejectedQualificationNeverShowsQualifiedOrPrize(){
        for(status in listOf("ELIMINATED","DISQUALIFIED","TIME_OVER")){
            val rejected=state(1,3,3);rejected.getJSONObject("official_progress").getJSONObject("1").put("status",status)
            val result=RoundResult.copy(rejected,1,3)!!;assertFalse(result.detail.contains("qualified"));assertEquals(0,result.place)
        }
    }
    @Test fun finalPrizeFollowsTheConfiguredLastRound(){
        for(last in 1..3){
            val result=state(last,2,2,1).put("package",JSONObject().put("settings",JSONObject().put("round_count",last)))
            assertEquals("YOU WON!",RoundResult.copy(result,last,2)!!.title)
            if(last>1){val waiting=state(last-1,2,2).put("package",result.getJSONObject("package"));assertEquals(0,RoundResult.copy(waiting,last-1,2)!!.place)}
        }
    }
}
