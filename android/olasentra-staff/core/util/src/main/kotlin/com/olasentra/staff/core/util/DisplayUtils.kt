package com.olasentra.staff.core.util

fun displayInitials(name: String): String {
    val parts = name.trim().split(Regex("\\s+")).filter { it.isNotBlank() }
    return when {
        parts.size >= 2 -> "${parts.first().first()}${parts.last().first()}".uppercase()
        parts.size == 1 && parts[0].length >= 2 -> parts[0].take(2).uppercase()
        parts.size == 1 -> parts[0].take(1).uppercase()
        else -> "?"
    }
}

fun formatStaffRoleLabel(rawRole: String?): String {
    val role = rawRole?.trim()?.lowercase().orEmpty()
    if (role.isBlank() || role == "—") return "—"
    return when (role) {
        "static" -> "Static Security"
        "dsp" -> "Door Supervisor"
        "both" -> "DSP & Static Security"
        "fire_marshal" -> "Fire Marshal"
        "steward" -> "Steward"
        else -> role.split('_', ' ')
            .filter { it.isNotBlank() }
            .joinToString(" ") { word -> word.replaceFirstChar { ch -> ch.uppercase() } }
    }
}

fun stripHtmlForDisplay(value: String?): String {
    if (value.isNullOrBlank()) return ""
    return value
        .replace(Regex("(?i)<br\\s*/?>"), "\n")
        .replace(Regex("(?i)</p>\\s*"), "\n")
        .replace(Regex("(?i)</div>\\s*"), "\n")
        .replace(Regex("<[^>]+>"), "")
        .replace("&nbsp;", " ")
        .replace("&amp;", "&")
        .replace("&lt;", "<")
        .replace("&gt;", ">")
        .replace("&quot;", "\"")
        .replace("&#39;", "'")
        .replace(Regex("\\n{3,}"), "\n\n")
        .trim()
}
