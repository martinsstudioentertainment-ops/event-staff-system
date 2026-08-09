package com.olasentra.staff.navigation

import com.olasentra.staff.core.navigation.DeepLinkDestination
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.asSharedFlow

@Singleton
class PendingDeepLinkBus @Inject constructor(
    private val deepLinkResolver: NotificationDeepLinkResolver,
) {
    private val _events = MutableSharedFlow<String>(extraBufferCapacity = 1)
    val events: SharedFlow<String> = _events.asSharedFlow()

    fun publishDestination(destination: DeepLinkDestination) {
        publishRoute(deepLinkResolver.routeForDestination(destination))
    }

    fun publishRoute(route: String) {
        _events.tryEmit(route)
    }
}
