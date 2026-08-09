package com.olasentra.staff.core.preferences.di

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.preferencesDataStore
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.android.qualifiers.ApplicationContext
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

private val Context.devicePreferencesDataStore: DataStore<Preferences> by preferencesDataStore(
    name = "olasentra_device_preferences",
)

@Module
@InstallIn(SingletonComponent::class)
object PreferencesModule {

    @Provides
    @Singleton
    fun provideDevicePreferencesDataStore(
        @ApplicationContext context: Context,
    ): DataStore<Preferences> = context.devicePreferencesDataStore
}
