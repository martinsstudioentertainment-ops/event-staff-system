package com.olasentra.staff

import android.app.Application
import androidx.hilt.work.HiltWorkerFactory
import androidx.work.Configuration
import com.olasentra.staff.core.util.AppLogger
import com.olasentra.staff.sync.OfflineSyncWorker
import dagger.hilt.android.HiltAndroidApp
import javax.inject.Inject
import timber.log.Timber

@HiltAndroidApp
class OlasentraStaffApplication : Application(), Configuration.Provider {

    @Inject lateinit var appLogger: AppLogger
    @Inject lateinit var workerFactory: HiltWorkerFactory

    override val workManagerConfiguration: Configuration
        get() = Configuration.Builder()
            .setWorkerFactory(workerFactory)
            .build()

    override fun onCreate() {
        super.onCreate()
        if (BuildConfig.DEBUG) {
            Timber.plant(Timber.DebugTree())
        }
        appLogger.i(TAG, "Olasentra Staff ${BuildConfig.VERSION_NAME} started")
        OfflineSyncWorker.schedule(this)
    }

    private companion object {
        const val TAG = "OlasentraStaffApplication"
    }
}
