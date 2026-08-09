package com.olasentra.staff.core.location

interface LocationProvider {
    suspend fun getCurrentLocation(): Result<DeviceLocation>
}
