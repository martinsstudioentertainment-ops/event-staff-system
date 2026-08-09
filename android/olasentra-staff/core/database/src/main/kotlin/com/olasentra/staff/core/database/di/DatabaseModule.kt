package com.olasentra.staff.core.database.di

import android.content.Context
import androidx.room.Room
import com.olasentra.staff.core.database.MIGRATION_1_2
import com.olasentra.staff.core.database.OlasentraDatabase
import com.olasentra.staff.core.database.dao.ApiCacheDao
import com.olasentra.staff.core.database.dao.OfflineSyncQueueDao
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.android.qualifiers.ApplicationContext
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
object DatabaseModule {

    @Provides
    @Singleton
    fun provideOlasentraDatabase(
        @ApplicationContext context: Context,
    ): OlasentraDatabase {
        return Room.databaseBuilder(
            context,
            OlasentraDatabase::class.java,
            DATABASE_NAME,
        )
            .addMigrations(MIGRATION_1_2)
            .build()
    }

    @Provides
    fun provideApiCacheDao(
        database: OlasentraDatabase,
    ): ApiCacheDao = database.apiCacheDao()

    @Provides
    fun provideOfflineSyncQueueDao(
        database: OlasentraDatabase,
    ): OfflineSyncQueueDao = database.offlineSyncQueueDao()

    private const val DATABASE_NAME = "olasentra_staff.db"
}
