package com.olasentra.staff.core.location

import android.annotation.SuppressLint
import android.content.Context
import android.location.Location
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.tasks.CancellationTokenSource
import dagger.hilt.android.qualifiers.ApplicationContext
import javax.inject.Inject
import javax.inject.Singleton
import kotlin.coroutines.resume
import kotlinx.coroutines.suspendCancellableCoroutine

@Singleton
class FusedLocationProviderImpl @Inject constructor(
    @ApplicationContext private val context: Context,
) : LocationProvider {

    private val fusedClient = LocationServices.getFusedLocationProviderClient(context)

    @SuppressLint("MissingPermission")
    override suspend fun getCurrentLocation(): Result<DeviceLocation> {
        return suspendCancellableCoroutine { continuation ->
            val cancellationSource = CancellationTokenSource()
            continuation.invokeOnCancellation {
                cancellationSource.cancel()
            }

            fusedClient.getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, cancellationSource.token)
                .addOnSuccessListener { location: Location? ->
                    if (location == null) {
                        continuation.resume(Result.failure(IllegalStateException("Location unavailable")))
                    } else {
                        continuation.resume(Result.success(location.toDeviceLocation()))
                    }
                }
                .addOnFailureListener { error ->
                    continuation.resume(Result.failure(error))
                }
        }
    }

    private fun Location.toDeviceLocation(): DeviceLocation {
        return DeviceLocation(
            latitude = latitude,
            longitude = longitude,
            accuracyMeters = if (hasAccuracy()) accuracy else Float.MAX_VALUE,
        )
    }
}
