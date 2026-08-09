package com.olasentra.staff.di

import com.olasentra.staff.core.util.AppLogger
import com.olasentra.staff.core.util.DefaultDispatcherProvider
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.core.util.TimberAppLogger
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
object AppModule {

    @Provides
    @Singleton
    fun provideDispatcherProvider(): DispatcherProvider = DefaultDispatcherProvider

    @Provides
    @Singleton
    fun provideAppLogger(): AppLogger = TimberAppLogger
}
