package com.olasentra.staff.core.security.di

import com.olasentra.staff.core.security.EncryptedTokenStorage
import com.olasentra.staff.core.security.SessionEventPublisher
import com.olasentra.staff.core.security.SessionEventPublisherImpl
import com.olasentra.staff.core.security.TokenStorage
import dagger.Binds
import dagger.Module
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
abstract class SecurityModule {

    @Binds
    @Singleton
    abstract fun bindTokenStorage(
        encryptedTokenStorage: EncryptedTokenStorage,
    ): TokenStorage

    @Binds
    @Singleton
    abstract fun bindSessionEventPublisher(
        impl: SessionEventPublisherImpl,
    ): SessionEventPublisher
}
