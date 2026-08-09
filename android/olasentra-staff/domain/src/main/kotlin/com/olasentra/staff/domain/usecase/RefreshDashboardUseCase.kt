package com.olasentra.staff.domain.usecase

import com.olasentra.staff.domain.repository.DashboardRepository

class RefreshDashboardUseCase(
    private val dashboardRepository: DashboardRepository,
) {
    suspend operator fun invoke() = dashboardRepository.refreshDashboard()
}
