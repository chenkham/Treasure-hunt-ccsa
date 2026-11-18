package com.campushunt.app

import org.json.JSONObject

object RoundResult {
    data class Copy(val key: String,val title: String,val detail: String,val place: Int=0)
    fun copy(state: JSONObject,round: Int,total: Int): Copy? {
        val final=state.optJSONObject("package")?.optJSONObject("settings")?.optInt("round_count",3)?.coerceIn(1,3)?:3
        if(state.optBoolean("eliminated"))return if(round==final&&state.optJSONObject("rounds")?.optJSONObject(final.toString())?.optString("end_reason")=="PODIUM_COMPLETE")Copy("closed","HUNT CLOSED","The final places have been awarded. Thank you for playing.")else Copy("eliminated","ELIMINATED","Your expedition has ended. Ask an organizer if you need help.")
        val official=state.optJSONObject("official_progress")?.optJSONObject(round.toString())
        when(official?.optString("status")){
            "ELIMINATED"->return Copy("eliminated","ELIMINATED","Your hunt has ended. Ask an organizer if you need help.")
            "DISQUALIFIED"->return Copy("disqualified","DISQUALIFIED","Your team cannot continue. Speak to an organizer.")
            "TIME_OVER"->return Copy("time-over","HUNT ENDED","Time is up. Check the leaderboard for your result.")
        }
        val completed=state.optJSONObject("local_progress")?.optJSONObject(round.toString())?.optInt("completed",0)?:0
        val confirmed=(official?.optInt("completed",0)?:0)>=total
        if(!confirmed&&completed<total)return null
        if(round<final)return Copy("round-$round-${if(confirmed)"confirmed" else "pending"}","ROUND $round COMPLETE",
            if(confirmed)"You qualified. Wait for Round ${round+1} to start. The hunt continues."
            else "Your scans are saved. Reconnect to confirm qualification for Round ${round+1}.")
        val place=official?.optInt("podium_place",0)?:0
        return if(confirmed&&place in 1..3)Copy("place-$place",when(place){1->"YOU WON!";2->"2ND PLACE!";else->"3RD PLACE!"},
            "${when(place){1->"1st";2->"2nd";else->"3rd"}} place confirmed. Go to the organizer desk to claim your reward.",place)
        else Copy("finish-pending","FINISH SAVED","Your progress is saved. Connect to the internet to receive your final place. Keep the app open.")
    }
}
