package com.olasentra.staff.core.util



object GeoUtils {

    fun distanceMeters(lat1: Double, lng1: Double, lat2: Double, lng2: Double): Int {

        val dLat = Math.toRadians(lat2 - lat1)

        val dLng = Math.toRadians(lng2 - lng1)

        val a = Math.pow(Math.sin(dLat / 2), 2.0) +

            Math.cos(Math.toRadians(lat1)) * Math.cos(Math.toRadians(lat2)) * Math.pow(Math.sin(dLng / 2), 2.0)

        val c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a))

        return (6371000.0 * c).toInt()

    }

}