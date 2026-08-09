package com.olasentra.staff.data.remote.mapper

import com.olasentra.staff.core.network.dto.AvailableEventDto
import com.olasentra.staff.core.network.dto.EventsListResponse
import com.olasentra.staff.domain.model.AvailableEvent
import com.olasentra.staff.domain.model.AvailableEventsOverview
import javax.inject.Inject

class EventsMapper @Inject constructor() {

    fun toAvailableEventsOverview(response: EventsListResponse): AvailableEventsOverview {
        val events = response.events.orEmpty().mapNotNull(::toAvailableEvent)
        return AvailableEventsOverview(
            events = events,
            count = response.count ?: events.size,
        )
    }

    private fun toAvailableEvent(dto: AvailableEventDto): AvailableEvent? {
        val eventId = dto.eventId ?: return null
        return AvailableEvent(
            eventId = eventId,
            eventName = dto.eventName?.takeIf { it.isNotBlank() } ?: "Event",
            eventDate = dto.eventDate?.takeIf { it.isNotBlank() } ?: "—",
            venueName = dto.venueName?.takeIf { it.isNotBlank() } ?: "—",
            employer = dto.employer?.takeIf { it.isNotBlank() } ?: "—",
            startTime = dto.startTime?.takeIf { it.isNotBlank() } ?: "—",
            endTime = dto.endTime?.takeIf { it.isNotBlank() } ?: "—",
            timeLabel = dto.timeLabel?.takeIf { it.isNotBlank() }
                ?: listOfNotNull(
                    dto.startTime?.takeIf { it.isNotBlank() },
                    dto.endTime?.takeIf { it.isNotBlank() },
                ).joinToString(" – ").ifBlank { "—" },
            availableSpaces = dto.availableSpaces ?: 0,
            registrationStatus = dto.registrationStatus?.takeIf { it.isNotBlank() } ?: "none",
            approvalStatus = dto.approvalStatus?.takeIf { it.isNotBlank() } ?: "Not applied",
            canApply = dto.canApply == true,
            registrationId = dto.registrationId,
        )
    }
}
