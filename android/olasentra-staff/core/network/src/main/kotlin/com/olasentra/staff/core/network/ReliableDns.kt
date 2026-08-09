package com.olasentra.staff.core.network

import java.net.InetAddress
import java.net.UnknownHostException
import okhttp3.Dns

object ReliableDns : Dns {
    override fun lookup(hostname: String): List<InetAddress> {
        return try {
            Dns.SYSTEM.lookup(hostname)
        } catch (systemFailure: UnknownHostException) {
            try {
                InetAddress.getAllByName(hostname).toList()
            } catch (_: UnknownHostException) {
                throw systemFailure
            }
        }
    }
}
