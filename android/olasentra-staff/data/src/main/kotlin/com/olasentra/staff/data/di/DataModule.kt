package com.olasentra.staff.data.di

import com.olasentra.staff.data.repository.AuthRepositoryImpl
import com.olasentra.staff.data.repository.ConfigRepositoryImpl
import com.olasentra.staff.data.repository.DashboardRepositoryImpl
import com.olasentra.staff.data.repository.GpsRepositoryImpl
import com.olasentra.staff.data.repository.MessagesRepositoryImpl
import com.olasentra.staff.data.repository.NotificationsRepositoryImpl
import com.olasentra.staff.data.repository.OfflineSyncRepositoryImpl
import com.olasentra.staff.data.repository.ProfileRepositoryImpl
import com.olasentra.staff.data.repository.ShiftsRepositoryImpl
import com.olasentra.staff.data.repository.AttendanceRepositoryImpl
import com.olasentra.staff.data.repository.AvailabilityRepositoryImpl
import com.olasentra.staff.data.repository.DocumentsRepositoryImpl
import com.olasentra.staff.data.repository.EventsRepositoryImpl
import com.olasentra.staff.data.repository.PushRepositoryImpl
import com.olasentra.staff.data.repository.RegistrationRepositoryImpl
import com.olasentra.staff.domain.repository.AttendanceRepository
import com.olasentra.staff.domain.repository.AuthRepository
import com.olasentra.staff.domain.repository.AvailabilityRepository
import com.olasentra.staff.domain.repository.ConfigRepository
import com.olasentra.staff.domain.repository.DashboardRepository
import com.olasentra.staff.domain.repository.DocumentsRepository
import com.olasentra.staff.domain.repository.EventsRepository
import com.olasentra.staff.domain.repository.GpsRepository
import com.olasentra.staff.domain.repository.MessagesRepository
import com.olasentra.staff.domain.repository.NotificationsRepository
import com.olasentra.staff.domain.repository.OfflineSyncRepository
import com.olasentra.staff.domain.repository.ProfileRepository
import com.olasentra.staff.domain.repository.PushRepository
import com.olasentra.staff.domain.repository.RegistrationRepository
import com.olasentra.staff.domain.repository.ShiftsRepository
import dagger.Binds
import dagger.Module
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
abstract class DataModule {

    @Binds
    @Singleton
    abstract fun bindAuthRepository(impl: AuthRepositoryImpl): AuthRepository

    @Binds
    @Singleton
    abstract fun bindConfigRepository(impl: ConfigRepositoryImpl): ConfigRepository

    @Binds
    @Singleton
    abstract fun bindOfflineSyncRepository(impl: OfflineSyncRepositoryImpl): OfflineSyncRepository

    @Binds
    @Singleton
    abstract fun bindDashboardRepository(impl: DashboardRepositoryImpl): DashboardRepository

    @Binds
    @Singleton
    abstract fun bindProfileRepository(impl: ProfileRepositoryImpl): ProfileRepository

    @Binds
    @Singleton
    abstract fun bindShiftsRepository(impl: ShiftsRepositoryImpl): ShiftsRepository

    @Binds
    @Singleton
    abstract fun bindGpsRepository(impl: GpsRepositoryImpl): GpsRepository

    @Binds
    @Singleton
    abstract fun bindMessagesRepository(impl: MessagesRepositoryImpl): MessagesRepository

    @Binds
    @Singleton
    abstract fun bindNotificationsRepository(impl: NotificationsRepositoryImpl): NotificationsRepository

    @Binds
    @Singleton
    abstract fun bindAttendanceRepository(impl: AttendanceRepositoryImpl): AttendanceRepository

    @Binds
    @Singleton
    abstract fun bindDocumentsRepository(impl: DocumentsRepositoryImpl): DocumentsRepository

    @Binds
    @Singleton
    abstract fun bindAvailabilityRepository(impl: AvailabilityRepositoryImpl): AvailabilityRepository

    @Binds
    @Singleton
    abstract fun bindPushRepository(impl: PushRepositoryImpl): PushRepository

    @Binds
    @Singleton
    abstract fun bindEventsRepository(impl: EventsRepositoryImpl): EventsRepository

    @Binds
    @Singleton
    abstract fun bindRegistrationRepository(impl: RegistrationRepositoryImpl): RegistrationRepository
}
