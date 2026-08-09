package com.olasentra.staff.data.remote.mapper

import com.olasentra.staff.core.network.dto.AvailabilityDayDto
import com.olasentra.staff.core.network.dto.AvailabilityResponse
import com.olasentra.staff.core.network.dto.DocumentItemDto
import com.olasentra.staff.core.network.dto.DocumentsListResponse
import com.olasentra.staff.core.network.dto.DocumentsSummaryDto
import com.olasentra.staff.core.network.dto.GpsStatusResponse
import com.olasentra.staff.core.network.dto.VenueDistanceDto
import com.olasentra.staff.core.network.dto.MessageItemDto
import com.olasentra.staff.core.network.dto.MessagesResponse
import com.olasentra.staff.core.network.dto.NotificationCategoryDto
import com.olasentra.staff.core.network.dto.NotificationItemDto
import com.olasentra.staff.core.network.dto.NotificationsListResponse
import com.olasentra.staff.core.network.dto.ShiftObjectDto
import com.olasentra.staff.core.network.dto.ShiftTodayResponse
import com.olasentra.staff.core.network.dto.ShiftsListResponse
import com.olasentra.staff.domain.model.AvailabilityDay
import com.olasentra.staff.domain.model.AvailabilityOverview
import com.olasentra.staff.domain.model.DocumentsOverview
import com.olasentra.staff.domain.model.DocumentsSummary
import com.olasentra.staff.domain.model.GpsStatusSummary
import com.olasentra.staff.domain.model.StaffDocument
import com.olasentra.staff.domain.model.VenueDistanceInfo
import com.olasentra.staff.domain.model.MessagesOverview
import com.olasentra.staff.domain.model.NotificationCategory
import com.olasentra.staff.domain.model.NotificationsOverview
import com.olasentra.staff.domain.model.ShiftDetail
import com.olasentra.staff.domain.model.ShiftFilter
import com.olasentra.staff.domain.model.ShiftSummary
import com.olasentra.staff.domain.model.ShiftTodaySummary
import com.olasentra.staff.domain.model.ShiftsOverview
import com.olasentra.staff.domain.model.StaffMessage
import com.olasentra.staff.domain.model.StaffNotification
import javax.inject.Inject

class ShiftMapper @Inject constructor() {

    fun toShiftsOverview(
        listResponse: ShiftsListResponse,
        todayResponse: ShiftTodayResponse?,
        filter: ShiftFilter,
    ): ShiftsOverview {
        return ShiftsOverview(
            today = todayResponse?.let(::toShiftTodaySummary),
            shifts = listResponse.shifts.orEmpty().map(::toShiftSummary),
            activeFilter = filter,
        )
    }

    fun toShiftDetail(dto: ShiftObjectDto): ShiftDetail {
        val registrationId = dto.registrationId
            ?: throw IllegalStateException("Missing registration id in shift detail")
        val attendance = dto.attendance

        return ShiftDetail(
            registrationId = registrationId,
            eventName = dto.eventName?.takeIf { it.isNotBlank() } ?: "Event",
            venueName = dto.venue?.name?.takeIf { it.isNotBlank() } ?: "—",
            eventDate = dto.eventDate?.takeIf { it.isNotBlank() } ?: "—",
            startTime = formatTime(dto),
            endTime = dto.endTime?.takeIf { it.isNotBlank() } ?: "—",
            statusLabel = dto.shiftStatusLabel?.takeIf { it.isNotBlank() }
                ?: dto.shiftStatus?.replaceFirstChar { it.uppercase() }
                ?: "—",
            assignedCompany = dto.assignedCompany?.takeIf { it.isNotBlank() } ?: "—",
            checkInAllowed = dto.checkInEligibility?.allowed == true,
            checkInReason = dto.checkInEligibility?.reason,
            checkOutAllowed = dto.checkOutEligibility?.allowed == true,
            checkOutReason = dto.checkOutEligibility?.reason,
            isCheckedIn = attendance?.isCheckedIn == true,
            checkedInAt = attendance?.checkedInAt,
            checkedOutAt = attendance?.checkedOutAt,
            attendanceStatus = attendance?.attendanceStatus,
        )
    }

    fun toShiftSummary(dto: ShiftObjectDto): ShiftSummary {
        return ShiftSummary(
            registrationId = dto.registrationId,
            eventName = dto.eventName?.takeIf { it.isNotBlank() } ?: "Event",
            venueName = dto.venue?.name?.takeIf { it.isNotBlank() } ?: "—",
            eventDate = dto.eventDate?.takeIf { it.isNotBlank() } ?: "—",
            startTime = formatTime(dto),
            endTime = dto.endTime?.takeIf { it.isNotBlank() } ?: "—",
            statusLabel = dto.shiftStatusLabel?.takeIf { it.isNotBlank() }
                ?: dto.shiftStatus?.replaceFirstChar { it.uppercase() }
                ?: "—",
            assignedCompany = dto.assignedCompany?.takeIf { it.isNotBlank() } ?: "—",
        )
    }

    private fun toShiftTodaySummary(response: ShiftTodayResponse): ShiftTodaySummary {
        val checkin = response.checkin
        return ShiftTodaySummary(
            shift = response.shift?.let(::toShiftSummary),
            hasShiftToday = checkin?.hasShiftToday == true || response.shift != null,
            checkedIn = checkin?.checkedIn == true || response.shift?.attendance?.isCheckedIn == true,
            attendanceStatus = checkin?.attendanceStatus
                ?: response.shift?.attendance?.attendanceStatus,
        )
    }

    private fun formatTime(dto: ShiftObjectDto): String {
        return dto.timeLabel?.takeIf { it.isNotBlank() }
            ?: dto.startTime?.takeIf { it.isNotBlank() }
            ?: "—"
    }
}

class GpsMapper @Inject constructor() {

    fun toGpsStatusSummary(response: GpsStatusResponse): GpsStatusSummary {
        val shift = response.shift
        val attendance = response.attendance
        val policy = response.policy

        val checkedIn = attendance?.isCheckedIn == true || attendance?.checkedIn == true
        val attendanceState: String = when {
            !attendance?.attendanceStatus.isNullOrBlank() -> attendance.attendanceStatus.orEmpty()
            checkedIn && attendance?.checkedOutAt.isNullOrBlank() -> "Checked in"
            !attendance?.checkedOutAt.isNullOrBlank() -> "Checked out"
            response.monitoring == true -> "Monitoring active"
            else -> "Not checked in"
        }

        return GpsStatusSummary(
            monitoringActive = response.monitoring == true,
            liveTracking = response.live == true,
            gpsEnabled = policy?.gpsEnabled != false,
            eventName = shift?.eventName,
            registrationId = shift?.registrationId,
            eventDate = shift?.eventDate,
            venueName = shift?.venue?.name,
            venueLat = shift?.venue?.locationLat,
            venueLng = shift?.venue?.locationLng,
            shiftStartTime = shift?.startTime,
            shiftEndTime = shift?.endTime,
            shiftStatusLabel = shift?.shiftStatusLabel ?: shift?.shiftStatus,
            assignedCompany = shift?.assignedCompany,
            checkInAllowed = shift?.checkInEligibility?.allowed == true,
            checkInReason = shift?.checkInEligibility?.reason,
            checkOutAllowed = shift?.checkOutEligibility?.allowed == true,
            checkOutReason = shift?.checkOutEligibility?.reason,
            isCheckedIn = checkedIn,
            attendanceState = attendanceState,
            checkedInAt = attendance?.checkedInAt,
            checkedOutAt = attendance?.checkedOutAt,
            hoursWorked = attendance?.hoursWorked,
            maxAccuracyM = policy?.maxAccuracyM,
            monitoringRequired = response.monitoring == true,
            venueDistance = response.venueDistance?.toVenueDistanceInfo(),
        )
    }

    fun toVenueDistanceInfo(dto: VenueDistanceDto?): VenueDistanceInfo? {
        return dto?.toVenueDistanceInfo()
    }
}

private fun VenueDistanceDto.toVenueDistanceInfo(): VenueDistanceInfo {
    return VenueDistanceInfo(
        distanceM = distanceM,
        radiusM = radiusM,
        inZone = inZone,
    )
}

class MessagesMapper @Inject constructor() {

    fun toMessagesOverview(response: MessagesResponse): MessagesOverview {
        return MessagesOverview(
            inbox = response.inbox.orEmpty().map(::toStaffMessage),
            sent = response.sent.orEmpty().map(::toStaffMessage),
            unreadCount = response.unreadCount ?: 0,
        )
    }

    private fun toStaffMessage(dto: MessageItemDto): StaffMessage {
        val id = dto.id ?: throw IllegalStateException("Missing message id")
        val folder = dto.folder?.takeIf { it.isNotBlank() } ?: "inbox"
        val isFromStaff = folder.equals("sent", ignoreCase = true)
        return StaffMessage(
            id = id,
            folder = folder,
            subject = dto.subject?.takeIf { it.isNotBlank() } ?: "(No subject)",
            body = dto.body?.takeIf { it.isNotBlank() } ?: "",
            isRead = dto.isRead == true,
            createdAt = dto.createdAt?.takeIf { it.isNotBlank() } ?: "—",
            senderLabel = dto.senderLabel?.takeIf { it.isNotBlank() }
                ?: if (isFromStaff) "You" else "Olasentra",
            isFromStaff = isFromStaff,
        )
    }
}

class NotificationsMapper @Inject constructor() {

    fun toNotificationsOverview(response: NotificationsListResponse): NotificationsOverview {
        return NotificationsOverview(
            notifications = response.notifications.orEmpty().map(::toStaffNotification),
            categories = response.categories.orEmpty().map(::toNotificationCategory),
            unreadCount = response.unreadCount ?: 0,
        )
    }

    private fun toStaffNotification(dto: NotificationItemDto): StaffNotification {
        val id = dto.id ?: throw IllegalStateException("Missing notification id")
        return StaffNotification(
            id = id,
            type = dto.type?.takeIf { it.isNotBlank() } ?: "",
            category = dto.category?.takeIf { it.isNotBlank() } ?: "system_announcement",
            categoryLabel = dto.categoryLabel?.takeIf { it.isNotBlank() } ?: "Notification",
            title = dto.title?.takeIf { it.isNotBlank() } ?: "Notification",
            body = dto.body?.takeIf { it.isNotBlank() } ?: "",
            actionUrl = dto.actionUrl?.takeIf { it.isNotBlank() },
            actionLabel = dto.actionLabel?.takeIf { it.isNotBlank() },
            relatedId = dto.relatedId,
            isRead = dto.isRead == true,
            createdAt = dto.createdAt?.takeIf { it.isNotBlank() } ?: "—",
        )
    }

    private fun toNotificationCategory(dto: NotificationCategoryDto): NotificationCategory {
        return NotificationCategory(
            category = dto.category?.takeIf { it.isNotBlank() } ?: "system_announcement",
            label = dto.label?.takeIf { it.isNotBlank() } ?: "Other",
        )
    }
}

class DocumentsMapper @Inject constructor() {

    fun toDocumentsOverview(response: DocumentsListResponse): DocumentsOverview {
        return DocumentsOverview(
            documents = response.documents.orEmpty().map(::toStaffDocument),
            summary = toDocumentsSummary(response.summary),
        )
    }

    fun toStaffDocument(dto: DocumentItemDto): StaffDocument {
        val key = dto.key?.takeIf { it.isNotBlank() } ?: throw IllegalStateException("Missing document key")
        return StaffDocument(
            key = key,
            label = dto.label?.takeIf { it.isNotBlank() } ?: key,
            category = dto.category?.takeIf { it.isNotBlank() } ?: "other",
            type = dto.type?.takeIf { it.isNotBlank() } ?: "file",
            expiry = dto.expiry?.takeIf { it.isNotBlank() },
            status = dto.status?.takeIf { it.isNotBlank() } ?: "unknown",
            approvalStatus = dto.approvalStatus?.takeIf { it.isNotBlank() } ?: "unknown",
            hasFile = dto.hasFile == true,
            licenceNumber = dto.licenceNumber?.takeIf { it.isNotBlank() },
        )
    }

    private fun toDocumentsSummary(dto: DocumentsSummaryDto?): DocumentsSummary {
        return DocumentsSummary(
            total = dto?.total ?: 0,
            valid = dto?.valid ?: 0,
            expiring = dto?.expiring ?: 0,
            expired = dto?.expired ?: 0,
            missing = dto?.missing ?: 0,
        )
    }
}

class AvailabilityMapper @Inject constructor() {

    fun toAvailabilityOverview(response: AvailabilityResponse): AvailabilityOverview {
        val month = response.month?.takeIf { it.isNotBlank() }
            ?: throw IllegalStateException("Missing availability month")
        return AvailabilityOverview(
            month = month,
            days = response.days.orEmpty().map(::toAvailabilityDay),
            settableStatuses = response.statuses.orEmpty(),
        )
    }

    fun toAvailabilityDay(dto: AvailabilityDayDto): AvailabilityDay {
        val date = dto.date?.takeIf { it.isNotBlank() } ?: throw IllegalStateException("Missing date")
        return AvailabilityDay(
            date = date,
            status = dto.status?.takeIf { it.isNotBlank() } ?: "available",
            approvalStatus = dto.approvalStatus?.takeIf { it.isNotBlank() } ?: "none",
            notes = dto.notes.orEmpty(),
            adminApproved = dto.adminApproved == true,
            updatedAt = dto.updatedAt.orEmpty(),
        )
    }
}
