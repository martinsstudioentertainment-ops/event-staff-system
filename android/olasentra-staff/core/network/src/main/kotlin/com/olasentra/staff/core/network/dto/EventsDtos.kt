package com.olasentra.staff.core.network.dto

import com.squareup.moshi.Json
import com.squareup.moshi.JsonClass

@JsonClass(generateAdapter = true)
data class AvailableEventDto(
    @Json(name = "event_id") val eventId: Long? = null,
    @Json(name = "event_name") val eventName: String? = null,
    @Json(name = "event_date") val eventDate: String? = null,
    @Json(name = "event_date_iso") val eventDateIso: String? = null,
    @Json(name = "venue_name") val venueName: String? = null,
    val employer: String? = null,
    @Json(name = "start_time") val startTime: String? = null,
    @Json(name = "end_time") val endTime: String? = null,
    @Json(name = "time_label") val timeLabel: String? = null,
    @Json(name = "available_spaces") val availableSpaces: Int? = null,
    @Json(name = "capacity_needed") val capacityNeeded: Int? = null,
    @Json(name = "capacity_filled") val capacityFilled: Int? = null,
    @Json(name = "is_full") val isFull: Boolean? = null,
    @Json(name = "registration_status") val registrationStatus: String? = null,
    @Json(name = "registration_id") val registrationId: Long? = null,
    @Json(name = "approval_status") val approvalStatus: String? = null,
    @Json(name = "can_apply") val canApply: Boolean? = null,
)

@JsonClass(generateAdapter = true)
data class EventsListResponse(
    val ok: Boolean? = null,
    val events: List<AvailableEventDto>? = null,
    val count: Int? = null,
)

@JsonClass(generateAdapter = true)
data class EventsRegisterRequest(
    @Json(name = "event_ids") val eventIds: List<Long>,
)

@JsonClass(generateAdapter = true)
data class EventsRegisterResponse(
    val ok: Boolean? = null,
    val message: String? = null,
    @Json(name = "registration_ids") val registrationIds: List<Long>? = null,
    val count: Int? = null,
)
