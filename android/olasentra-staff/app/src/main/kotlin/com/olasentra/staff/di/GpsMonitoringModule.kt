package com.olasentra.staff.di

import com.olasentra.staff.domain.repository.GpsMonitoringController
import com.olasentra.staff.gps.GpsMonitoringControllerImpl
import dagger.Binds
import dagger.Module
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
abstract class GpsMonitoringModule {

    @Binds
    @Singleton
    abstract fun bindGpsMonitoringController(
        impl: GpsMonitoringControllerImpl,
    ): GpsMonitoringController
}
