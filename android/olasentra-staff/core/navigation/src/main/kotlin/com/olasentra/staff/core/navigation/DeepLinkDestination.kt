package com.olasentra.staff.core.navigation



sealed class DeepLinkDestination {

    data object Notifications : DeepLinkDestination()

    data object Messages : DeepLinkDestination()

    data object Documents : DeepLinkDestination()

    data object Availability : DeepLinkDestination()

    data object Shifts : DeepLinkDestination()

    data object CheckIn : DeepLinkDestination()

    data object Dashboard : DeepLinkDestination()

    data class ShiftDetail(val registrationId: Long) : DeepLinkDestination()

}