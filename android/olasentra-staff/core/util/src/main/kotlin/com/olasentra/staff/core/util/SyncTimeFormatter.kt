package com.olasentra.staff.core.util

import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

object SyncTimeFormatter {

    private val formatter = DateTimeFormatter.ofPattern("d MMM yyyy, HH:mm", Locale.getDefault())

    fun format(epochMs: Long?): String {
        if (epochMs == null || epochMs <= 0L) {
            return "Never synced"
        }
        val instant = Instant.ofEpochMilli(epochMs)
        val zoned = instant.atZone(ZoneId.systemDefault())
        return "Last synced ${formatter.format(zoned)}"
    }
}
