package com.olasentra.staff.di

import com.olasentra.staff.BuildConfig
import com.olasentra.staff.core.network.MobileApi
import com.olasentra.staff.core.network.NetworkLogging
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent

@Module
@InstallIn(SingletonComponent::class)
object NetworkConfigModule {

    @Provides
    @MobileApi
    fun provideMobileApiBaseUrl(): String = BuildConfig.MOBILE_API_BASE_URL

    @Provides
    fun provideNetworkLogging(): NetworkLogging {
        return object : NetworkLogging {
            override val isEnabled: Boolean = BuildConfig.DEBUG
        }
    }
}
