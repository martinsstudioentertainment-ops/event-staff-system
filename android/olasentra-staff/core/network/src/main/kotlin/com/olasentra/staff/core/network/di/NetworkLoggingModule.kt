package com.olasentra.staff.core.network.di

import com.olasentra.staff.core.network.NetworkLogging
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton
import okhttp3.logging.HttpLoggingInterceptor

@Module
@InstallIn(SingletonComponent::class)
object NetworkLoggingModule {

    @Provides
    @Singleton
    fun provideHttpLoggingInterceptor(
        networkLogging: NetworkLogging,
    ): HttpLoggingInterceptor {
        return HttpLoggingInterceptor().apply {
            level = if (networkLogging.isEnabled) {
                HttpLoggingInterceptor.Level.BASIC
            } else {
                HttpLoggingInterceptor.Level.NONE
            }
        }
    }
}
