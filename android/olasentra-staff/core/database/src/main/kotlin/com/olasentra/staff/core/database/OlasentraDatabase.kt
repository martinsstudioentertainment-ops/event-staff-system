package com.olasentra.staff.core.database

import androidx.room.Database
import androidx.room.RoomDatabase
import com.olasentra.staff.core.database.dao.ApiCacheDao
import com.olasentra.staff.core.database.dao.OfflineSyncQueueDao
import com.olasentra.staff.core.database.entity.ApiCacheEntity
import com.olasentra.staff.core.database.entity.OfflineSyncQueueEntity

@Database(
    entities = [
        OfflineSyncQueueEntity::class,
        ApiCacheEntity::class,
    ],
    version = 2,
    exportSchema = false,
)
abstract class OlasentraDatabase : RoomDatabase() {
    abstract fun offlineSyncQueueDao(): OfflineSyncQueueDao

    abstract fun apiCacheDao(): ApiCacheDao
}
