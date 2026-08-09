package com.olasentra.staff.feature.gps.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.location.DeviceLocation
import com.olasentra.staff.core.location.LocationPermissionChecker
import com.olasentra.staff.core.location.LocationPermissionState
import com.olasentra.staff.core.location.LocationProvider
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.core.util.GeoUtils
import com.olasentra.staff.domain.model.GpsStatusSummary
import com.olasentra.staff.domain.model.VenueDistanceInfo
import com.olasentra.staff.domain.repository.AttendanceRepository
import com.olasentra.staff.domain.repository.GpsMonitoringController
import com.olasentra.staff.domain.repository.GpsRepository
import com.olasentra.staff.domain.repository.OfflineSyncRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

@HiltViewModel
class CheckInViewModel @Inject constructor(
    private val gpsRepository: GpsRepository,
    private val attendanceRepository: AttendanceRepository,
    private val offlineSyncRepository: OfflineSyncRepository,
    private val locationProvider: LocationProvider,
    private val gpsMonitoringController: GpsMonitoringController,
    private val locationPermissionChecker: LocationPermissionChecker,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val _uiState = MutableStateFlow(CheckInUiState(isInitialLoading = true))
    val uiState: StateFlow<CheckInUiState> = _uiState.asStateFlow()

    private var locationRefreshJob: Job? = null

    init {
        viewModelScope.launch(dispatchers.io) {
            gpsRepository.observeGpsStatus().collect { resource ->
                val status = resource.data
                _uiState.update { current ->
                    current.copy(
                        status = status,
                        lastSyncedAtEpochMs = resource.lastSyncedAtEpochMs,
                        isRefreshing = resource.isRefreshing,
                        errorMessage = if (resource.data == null) resource.errorMessage else null,
                        isInitialLoading = resource.data == null && resource.isRefreshing,
                        showOfflineBanner = resource.isFromCache && resource.errorMessage != null,
                    ).withActionAvailability(status)
                }
                updateMonitoring(status)
            }
        }

        viewModelScope.launch(dispatchers.io) {
            offlineSyncRepository.observePendingCount().collect { count ->
                _uiState.update { it.copy(pendingOfflineCount = count) }
            }
        }

        if (locationPermissionChecker.hasFineLocationPermission()) {
            _uiState.update {
                it.copy(locationPermission = LocationPermissionState.GRANTED)
                    .withActionAvailability(it.status)
            }
            startLocationRefresh()
        }

        refresh()
    }

    fun onPermissionResult(granted: Boolean, shouldShowRationale: Boolean) {
        val permissionState = when {
            granted -> LocationPermissionState.GRANTED
            shouldShowRationale -> LocationPermissionState.DENIED
            _uiState.value.hasRequestedPermission -> LocationPermissionState.PERMANENTLY_DENIED
            else -> LocationPermissionState.DENIED
        }
        _uiState.update {
            it.copy(
                locationPermission = permissionState,
                hasRequestedPermission = true,
            ).withActionAvailability(it.status)
        }
        if (granted) {
            startLocationRefresh()
        }
    }

    fun syncPermissionFromSystem(shouldShowRationale: Boolean) {
        if (locationPermissionChecker.hasFineLocationPermission()) {
            _uiState.update {
                it.copy(locationPermission = LocationPermissionState.GRANTED)
                    .withActionAvailability(it.status)
            }
            if (locationRefreshJob?.isActive != true) {
                startLocationRefresh()
            }
        } else if (_uiState.value.hasRequestedPermission && !shouldShowRationale) {
            _uiState.update {
                it.copy(locationPermission = LocationPermissionState.PERMANENTLY_DENIED)
                    .withActionAvailability(it.status)
            }
        }
    }

    fun onPermissionNotRequestedYet() {
        if (!locationPermissionChecker.hasFineLocationPermission()) {
            _uiState.update {
                it.copy(locationPermission = LocationPermissionState.NOT_REQUESTED)
                    .withActionAvailability(it.status)
            }
        }
    }

    fun refresh() {
        viewModelScope.launch(dispatchers.io) {
            val registrationId = _uiState.value.status?.registrationId
            gpsRepository.refreshGpsStatus(registrationId)
            if (_uiState.value.locationPermission == LocationPermissionState.GRANTED) {
                refreshLocation()
            }
            attendanceRepository.syncPendingOfflineActions()
        }
    }

    fun refreshLocation() {
        viewModelScope.launch(dispatchers.io) {
            _uiState.update { it.copy(isLocationLoading = true, locationError = null) }
            val result = locationProvider.getCurrentLocation()
            result.fold(
                onSuccess = { location ->
                    val distanceInfo = computeDistanceInfo(location, _uiState.value.status)
                    _uiState.update { current ->
                        current.copy(
                            currentLocation = location,
                            isLocationLoading = false,
                            venueDistance = distanceInfo ?: current.status?.venueDistance,
                        ).withActionAvailability(current.status)
                    }
                    val registrationId = _uiState.value.status?.registrationId
                    if (_uiState.value.status?.isCheckedIn == true && registrationId != null) {
                        attendanceRepository.sendGpsPing(
                            latitude = location.latitude,
                            longitude = location.longitude,
                            accuracyMeters = location.accuracyMeters,
                            registrationId = registrationId,
                            queueIfOffline = true,
                        ).let { pingResult ->
                            pingResult.venueDistance?.let { venueDistance ->
                                _uiState.update { it.copy(venueDistance = venueDistance) }
                            }
                        }
                    }
                },
                onFailure = { error ->
                    _uiState.update {
                        it.copy(
                            isLocationLoading = false,
                            locationError = error.message ?: "Location unavailable",
                        ).withActionAvailability(it.status)
                    }
                },
            )
        }
    }

    fun checkIn() {
        val status = _uiState.value.status ?: return
        val registrationId = status.registrationId ?: return
        val location = _uiState.value.currentLocation ?: return
        if (!_uiState.value.checkInEnabled) return

        viewModelScope.launch(dispatchers.io) {
            _uiState.update { it.copy(isCheckingIn = true, actionError = null, actionMessage = null) }
            val result = attendanceRepository.checkIn(
                registrationId = registrationId,
                latitude = location.latitude,
                longitude = location.longitude,
                accuracyMeters = location.accuracyMeters,
            )
            _uiState.update {
                it.copy(
                    isCheckingIn = false,
                    actionMessage = result.message,
                    actionError = if (result.success) null else result.message,
                    venueDistance = result.venueDistance ?: it.venueDistance,
                )
            }
            if (result.success && result.monitoringRequired && registrationId > 0) {
                gpsMonitoringController.startMonitoring(registrationId)
            }
            refresh()
        }
    }

    fun checkOut() {
        val status = _uiState.value.status ?: return
        val registrationId = status.registrationId ?: return
        val location = _uiState.value.currentLocation ?: return
        if (!_uiState.value.checkOutEnabled) return

        viewModelScope.launch(dispatchers.io) {
            _uiState.update { it.copy(isCheckingOut = true, actionError = null, actionMessage = null) }
            val result = attendanceRepository.checkOut(
                registrationId = registrationId,
                latitude = location.latitude,
                longitude = location.longitude,
                accuracyMeters = location.accuracyMeters,
            )
            gpsMonitoringController.stopMonitoring()
            _uiState.update {
                it.copy(
                    isCheckingOut = false,
                    actionMessage = result.message,
                    actionError = if (result.success) null else result.message,
                )
            }
            refresh()
        }
    }

    private fun startLocationRefresh() {
        locationRefreshJob?.cancel()
        locationRefreshJob = viewModelScope.launch(dispatchers.io) {
            while (isActive) {
                refreshLocation()
                delay(LOCATION_REFRESH_MS)
            }
        }
    }

    private fun updateMonitoring(status: GpsStatusSummary?) {
        val registrationId = status?.registrationId
        if (status?.isCheckedIn == true && status.monitoringRequired && registrationId != null) {
            gpsMonitoringController.startMonitoring(registrationId)
        } else if (status?.isCheckedIn != true) {
            gpsMonitoringController.stopMonitoring()
        }
    }

    private fun computeDistanceInfo(
        location: DeviceLocation,
        status: GpsStatusSummary?,
    ): VenueDistanceInfo? {
        val venueLat = status?.venueLat
        val venueLng = status?.venueLng
        if (venueLat == null || venueLng == null) {
            return status?.venueDistance
        }
        val distanceM = GeoUtils.distanceMeters(
            location.latitude,
            location.longitude,
            venueLat,
            venueLng,
        )
        val radiusM = status.venueDistance?.radiusM ?: DEFAULT_RADIUS_M
        return VenueDistanceInfo(
            distanceM = distanceM,
            radiusM = radiusM,
            inZone = distanceM <= radiusM,
        )
    }

    private fun CheckInUiState.withActionAvailability(status: GpsStatusSummary?): CheckInUiState {
        if (status == null) {
            return copy(checkInEnabled = false, checkOutEnabled = false)
        }

        val hasLocation = currentLocation != null
        val accuracyOk = currentLocation?.let { location ->
            status.maxAccuracyM?.let { max ->
                location.accuracyMeters <= max
            } ?: true
        } ?: false
        val inZone = venueDistance?.inZone != false
        val permissionOk = locationPermission == LocationPermissionState.GRANTED

        val checkIn = permissionOk &&
            hasLocation &&
            accuracyOk &&
            inZone &&
            status.checkInAllowed &&
            !status.isCheckedIn &&
            status.registrationId != null &&
            !isCheckingIn &&
            !isCheckingOut

        val checkOut = permissionOk &&
            hasLocation &&
            accuracyOk &&
            status.isCheckedIn &&
            status.checkOutAllowed &&
            status.registrationId != null &&
            !isCheckingIn &&
            !isCheckingOut

        return copy(checkInEnabled = checkIn, checkOutEnabled = checkOut)
    }

    override fun onCleared() {
        locationRefreshJob?.cancel()
        super.onCleared()
    }

    private companion object {
        const val LOCATION_REFRESH_MS = 30_000L
        const val DEFAULT_RADIUS_M = 200
    }
}

data class CheckInUiState(
    val status: GpsStatusSummary? = null,
    val lastSyncedAtEpochMs: Long? = null,
    val isRefreshing: Boolean = false,
    val isInitialLoading: Boolean = false,
    val errorMessage: String? = null,
    val showOfflineBanner: Boolean = false,
    val locationPermission: LocationPermissionState = LocationPermissionState.NOT_REQUESTED,
    val currentLocation: DeviceLocation? = null,
    val isLocationLoading: Boolean = false,
    val locationError: String? = null,
    val venueDistance: VenueDistanceInfo? = null,
    val isCheckingIn: Boolean = false,
    val isCheckingOut: Boolean = false,
    val actionMessage: String? = null,
    val actionError: String? = null,
    val pendingOfflineCount: Int = 0,
    val hasRequestedPermission: Boolean = false,
    val checkInEnabled: Boolean = false,
    val checkOutEnabled: Boolean = false,
)
