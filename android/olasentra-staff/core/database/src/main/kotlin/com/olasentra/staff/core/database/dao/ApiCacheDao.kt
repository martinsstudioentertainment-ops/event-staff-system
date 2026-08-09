package com.olasentra.staff.core.database.dao

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import com.olasentra.staff.core.database.entity.ApiCacheEntity
import kotlinx.coroutines.flow.Flow

@Dao
interface ApiCacheDao {

    @Query("SELECT * FROM api_cache WHERE cache_key = :cacheKey LIMIT 1")
    fun observe(cacheKey: String): Flow<ApiCacheEntity?>

    @Query("SELECT * FROM api_cache WHERE cache_key = :cacheKey LIMIT 1")
    suspend fun get(cacheKey: String): ApiCacheEntity?

    @Query("DELETE FROM api_cache WHERE cache_key = :cacheKey")
    suspend fun delete(cacheKey: String)

    @Query("DELETE FROM api_cache")
    suspend fun deleteAll()

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsert(entity: ApiCacheEntity)
}
