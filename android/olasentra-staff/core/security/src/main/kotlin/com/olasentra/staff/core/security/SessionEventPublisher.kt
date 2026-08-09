package com.olasentra.staff.core.security

import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.asSharedFlow

interface SessionEventPublisher {
    val sessionExpiredEvents: SharedFlow<Unit>

    fun publishSessionExpired()
}

@Singleton
class SessionEventPublisherImpl @Inject constructor() : SessionEventPublisher {

    private val _sessionExpiredEvents = MutableSharedFlow<Unit>(extraBufferCapacity = 1)
    override val sessionExpiredEvents: SharedFlow<Unit> = _sessionExpiredEvents.asSharedFlow()

    override fun publishSessionExpired() {
        _sessionExpiredEvents.tryEmit(Unit)
    }
}
