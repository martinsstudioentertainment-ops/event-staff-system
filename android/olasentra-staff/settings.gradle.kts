pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}

dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
    }
}

rootProject.name = "OlasentraStaff"

include(":app")
include(":core:util")
include(":core:ui")
include(":core:network")
include(":core:security")
include(":core:database")
include(":core:preferences")
include(":core:navigation")
include(":core:location")
include(":domain")
include(":data")
include(":feature:auth")
include(":feature:dashboard")
include(":feature:profile")
include(":feature:shifts")
include(":feature:gps")
include(":feature:messages")
include(":feature:notifications")
include(":feature:documents")
include(":feature:availability")
