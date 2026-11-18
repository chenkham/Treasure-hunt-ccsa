package com.campushunt.app

/** First/cached standings establish a baseline. Only newer, foreground improvements celebrate. */
class FeedbackPolicy {
    private var scope=""
    private var revision=-1L
    private var rank: Int?=null
    private var lastNotice: Long?=null
    fun reset() { scope="";revision=-1;rank=null;lastNotice=null }
    fun quest(now: Long) { lastNotice=now }
    fun standing(eventRound: String,version: Long,current: Int?,eligible: Boolean,now: Long): Boolean {
        if(eventRound!=scope) { scope=eventRound;revision=version;rank=current;return false }
        if(version<=revision) return false
        val previous=rank;revision=version;rank=current
        if(!eligible || current==null || current<1 || previous==null || current>=previous) return false
        if(lastNotice?.let { now-it<30_000 }==true) return false
        lastNotice=now;return true
    }
}
