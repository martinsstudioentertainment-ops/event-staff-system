package com.olasentra.staff.domain.model



data class AvailableEvent(

    val eventId: Long,

    val eventName: String,

    val eventDate: String,

    val venueName: String,

    val employer: String,

    val startTime: String,

    val endTime: String,

    val timeLabel: String,

    val availableSpaces: Int,

    val registrationStatus: String,

    val approvalStatus: String,

    val canApply: Boolean,

    val registrationId: Long?,

)



data class AvailableEventsOverview(

    val events: List<AvailableEvent>,

    val count: Int = events.size,

)

