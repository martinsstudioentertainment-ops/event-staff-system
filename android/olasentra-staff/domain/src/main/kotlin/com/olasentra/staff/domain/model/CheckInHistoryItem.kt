package com.olasentra.staff.domain.model

data class CheckInHistoryItem(
    val eventName: String,
    val checkedInAt: String,
    val hoursWorked: Double?,
)
