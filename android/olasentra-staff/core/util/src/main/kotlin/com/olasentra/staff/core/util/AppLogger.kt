package com.olasentra.staff.core.util

import timber.log.Timber

interface AppLogger {
    fun d(tag: String, message: String, throwable: Throwable? = null)

    fun i(tag: String, message: String, throwable: Throwable? = null)

    fun w(tag: String, message: String, throwable: Throwable? = null)

    fun e(tag: String, message: String, throwable: Throwable? = null)
}

object TimberAppLogger : AppLogger {
    override fun d(tag: String, message: String, throwable: Throwable?) {
        if (throwable != null) {
            Timber.tag(tag).d(throwable, message)
        } else {
            Timber.tag(tag).d(message)
        }
    }

    override fun i(tag: String, message: String, throwable: Throwable?) {
        if (throwable != null) {
            Timber.tag(tag).i(throwable, message)
        } else {
            Timber.tag(tag).i(message)
        }
    }

    override fun w(tag: String, message: String, throwable: Throwable?) {
        if (throwable != null) {
            Timber.tag(tag).w(throwable, message)
        } else {
            Timber.tag(tag).w(message)
        }
    }

    override fun e(tag: String, message: String, throwable: Throwable?) {
        if (throwable != null) {
            Timber.tag(tag).e(throwable, message)
        } else {
            Timber.tag(tag).e(message)
        }
    }
}
