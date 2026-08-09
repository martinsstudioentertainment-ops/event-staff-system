package com.olasentra.staff.core.util

import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

private val irishDisplayFormatter = DateTimeFormatter.ofPattern("dd/MM/yyyy", Locale("en", "IE"))
private val isoFormatter = DateTimeFormatter.ISO_LOCAL_DATE

fun formatApiDateForDisplay(isoDate: String?): String? {
    val value = isoDate?.trim().orEmpty()
    if (value.isBlank()) {
        return null
    }
    return runCatching {
        LocalDate.parse(value, isoFormatter).format(irishDisplayFormatter)
    }.getOrNull()
}

fun parseDisplayDateToIso(displayDate: String): String? {
    val value = displayDate.trim()
    if (value.isBlank()) {
        return null
    }
    return runCatching {
        LocalDate.parse(value, irishDisplayFormatter).format(isoFormatter)
    }.getOrNull()
        ?: runCatching {
            LocalDate.parse(value, isoFormatter).format(isoFormatter)
        }.getOrNull()
}

fun localDateFromIso(isoDate: String): LocalDate? {
    val value = isoDate.trim()
    if (value.isBlank()) {
        return null
    }
    return runCatching {
        LocalDate.parse(value, isoFormatter)
    }.getOrNull()
}

fun localDateToIso(date: LocalDate): String {
    return date.format(isoFormatter)
}

fun localDateToEpochMillis(date: LocalDate): Long {
    return date.atStartOfDay(ZoneId.systemDefault()).toInstant().toEpochMilli()
}

fun epochMillisToLocalDate(epochMillis: Long): LocalDate {
    return Instant.ofEpochMilli(epochMillis).atZone(ZoneId.systemDefault()).toLocalDate()
}
