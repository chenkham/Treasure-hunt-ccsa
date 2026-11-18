package com.campushunt.app

import android.app.Activity
import android.content.Context
import android.graphics.Color
import android.view.Gravity
import android.view.inputmethod.InputMethodManager
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.TextView

/** Gameplay never opens an external IME/assistant toolbar. Enrollment remains an organizer setup flow. */
object LocalKeypad {
    fun show(activity: Activity,target: EditText,numeric: Boolean,extraCharacters: String="") {
        (activity.getSystemService(Context.INPUT_METHOD_SERVICE) as InputMethodManager)
            .hideSoftInputFromWindow(activity.window.decorView.windowToken,0)
        val density=activity.resources.displayMetrics.density
        fun dp(value: Int)=(value*density).toInt()
        val layout=LinearLayout(activity).apply { orientation=LinearLayout.VERTICAL; setPadding(dp(12),dp(10),dp(12),dp(6)) }
        val preview=TextView(activity).apply { textSize=21f; setTextColor(Color.rgb(23,60,43)); setPadding(dp(8),dp(12),dp(8),dp(16)); minHeight=dp(60) }
        fun update() { preview.text=if(numeric) "•".repeat(target.text.length) else target.text.toString() }
        update(); layout.addView(preview)
        val keys=LinearLayout(activity).apply { orientation=LinearLayout.VERTICAL }; layout.addView(keys)
        val extras=extraCharacters.codePoints().toArray().distinct().take(80).map { String(Character.toChars(it)) }
        var showExtras=false
        fun key(label: String,action: ()->Unit)=Button(activity).apply {
            text=label; isAllCaps=false; textSize=if(label.length>2) 13f else 16f
            minWidth=0; minimumWidth=0; minHeight=0; minimumHeight=0; setPadding(0,0,0,0)
            setTextColor(Color.rgb(23,60,43)); setOnClickListener { action(); update() }
            layoutParams=LinearLayout.LayoutParams(0,dp(48),1f).apply { setMargins(dp(1),dp(1),dp(1),dp(1)) }
        }
        fun render() {
            keys.removeAllViews()
            val rows=when {
                numeric -> listOf("123","456","789","0").map { it.map(Char::toString) }
                showExtras -> extras.chunked(8)
                else -> listOf("1234567890","qwertyuiop","asdfghjkl","zxcvbnm").map { it.map(Char::toString) }
            }
            for(row in rows) {
                val line=LinearLayout(activity).apply { gravity=Gravity.CENTER }
                for(letter in row) line.addView(key(letter) { if(target.text.length<2000) target.text.append(letter) })
                keys.addView(line)
            }
            val actions=LinearLayout(activity)
            if(!numeric) actions.addView(key("Space") { if(target.text.length<2000) target.text.append(" ") })
            actions.addView(key("⌫") {
                val value=target.text.toString()
                if(value.isNotEmpty()) target.setText(value.substring(0,value.offsetByCodePoints(value.length,-1)))
            })
            actions.addView(key("Clear") { target.text.clear() })
            if(!numeric && extras.isNotEmpty()) actions.addView(key(if(showExtras) "ABC" else "More") { showExtras=!showExtras; render() })
            keys.addView(actions)
        }
        render()
        val dialog=HuntDialogBuilder(activity).setTitle(if(numeric) "Organizer PIN" else "Search text")
            .setView(layout).setPositiveButton("Done",null).create()
        dialog.window?.setSoftInputMode(android.view.WindowManager.LayoutParams.SOFT_INPUT_STATE_ALWAYS_HIDDEN)
        dialog.show()
        dialog.window?.setLayout(android.view.ViewGroup.LayoutParams.MATCH_PARENT,android.view.ViewGroup.LayoutParams.WRAP_CONTENT)
    }
}
