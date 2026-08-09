package com.olasentra.staff.ui

import androidx.lifecycle.ViewModel
import com.olasentra.staff.core.security.SessionEventPublisher
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.SharedFlow

@HiltViewModel
class SessionNavigationViewModel @Inject constructor(
    sessionEventPublisher: SessionEventPublisher,
) : ViewModel() {

    val sessionExpiredEvents: SharedFlow<Unit> = sessionEventPublisher.sessionExpiredEvents
}
