package com.olasentra.staff.core.database



import androidx.room.migration.Migration

import androidx.sqlite.db.SupportSQLiteDatabase



val MIGRATION_1_2 = object : Migration(1, 2) {

    override fun migrate(db: SupportSQLiteDatabase) {

        db.execSQL(

            """

            CREATE TABLE IF NOT EXISTS api_cache (

                cache_key TEXT NOT NULL PRIMARY KEY,

                payload_json TEXT NOT NULL,

                synced_at_epoch_ms INTEGER NOT NULL

            )

            """.trimIndent(),

        )

    }

}