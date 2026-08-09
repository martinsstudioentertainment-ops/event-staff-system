package com.olasentra.staff.core.util

object AppVersionUtils {

    fun isUpdateRequired(current: String?, minimum: String?): Boolean {
        val min = minimum?.trim()?.takeIf { it.isNotEmpty() } ?: return false
        val cur = current?.trim()?.takeIf { it.isNotEmpty() } ?: return true
        return compareVersions(cur, min) < 0
    }

    fun compareVersions(left: String, right: String): Int {
        val leftParts = parseSegments(left)
        val rightParts = parseSegments(right)
        val max = maxOf(leftParts.size, rightParts.size)
        for (index in 0 until max) {
            val leftValue = leftParts.getOrElse(index) { 0 }
            val rightValue = rightParts.getOrElse(index) { 0 }
            if (leftValue != rightValue) {
                return leftValue.compareTo(rightValue)
            }
        }
        return 0
    }

    private fun parseSegments(version: String): List<Int> {
        val core = version.substringBefore('-').substringBefore('+')
        return core.split('.', '-', '_')
            .mapNotNull { segment ->
                segment.filter(Char::isDigit).toIntOrNull()
            }
    }
}
