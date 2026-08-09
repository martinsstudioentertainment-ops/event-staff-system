package com.olasentra.staff.domain.usecase

import com.olasentra.staff.domain.repository.DashboardRepository

class ObserveDashboardUseCase(
    private val dashboardRepository: DashboardRepository,
) {
    operator fun invoke() = dashboardRepository.observeDashboard()
}
