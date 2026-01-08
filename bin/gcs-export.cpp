#include <iostream>
#include <fstream>
#include <string>
#include <cstdlib>

#include <jsoncpp/json/json.h>

#include "settings.h"

/*
 * Paths
 */
static const char* OUTPUT_PATH =
    "/home/fpp/media/plugins/GoogleCalendarScheduler/runtime/fpp-env.json";

static const char* LOCALE_PATH =
    "/home/fpp/media/config/locale.json";

/*
 * Helper: load JSON file safely
 */
static bool loadJsonFile(const char* path, Json::Value& out, std::string& err)
{
    std::ifstream in(path);
    if (!in) {
        err = std::string("Unable to open ") + path;
        return false;
    }

    Json::CharReaderBuilder builder;
    builder["collectComments"] = false;

    return Json::parseFromStream(builder, in, &out, &err);
}

int main()
{
    Json::Value root(Json::objectValue);
    root["schemaVersion"] = 1;
    root["source"]        = "gcs-export";

    // ---------------------------------------------------------------------
    // Pull canonical values from FPP settings
    // ---------------------------------------------------------------------
    std::string latStr = getSetting("Latitude");
    std::string lonStr = getSetting("Longitude");
    std::string tz     = getSetting("TimeZone");

    double lat = latStr.empty() ? 0.0 : atof(latStr.c_str());
    double lon = lonStr.empty() ? 0.0 : atof(lonStr.c_str());

    root["latitude"]  = lat;
    root["longitude"] = lon;
    root["timezone"]  = tz;

    // ---------------------------------------------------------------------
    // Load locale JSON directly (no runtime dependencies)
    // ---------------------------------------------------------------------
    Json::Value locale(Json::objectValue);
    std::string localeErr;

    if (loadJsonFile(LOCALE_PATH, locale, localeErr)) {
        root["rawLocale"] = locale;
    } else {
        root["rawLocale"]  = Json::objectValue;
        root["localeError"] = localeErr;
        std::cerr << "WARN: " << localeErr << std::endl;
    }

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------
    bool ok = true;

    if (lat == 0.0 || lon == 0.0) {
        ok = false;
        root["error"] =
            "Latitude/Longitude not present (or zero) in FPP settings.";
        std::cerr
            << "WARN: Latitude/Longitude not present (or zero) in FPP settings."
            << std::endl;
    }

    if (tz.empty()) {
        ok = false;
        root["error"] =
            "Timezone not present in FPP settings.";
        std::cerr
            << "WARN: Timezone not present in FPP settings."
            << std::endl;
    }

    root["ok"] = ok;

    // ---------------------------------------------------------------------
    // Write output atomically
    // ---------------------------------------------------------------------
    std::ofstream out(OUTPUT_PATH);
    if (!out) {
        std::cerr << "ERROR: Unable to write " << OUTPUT_PATH << std::endl;
        return 2;
    }

    out << root.toStyledString();
    out.close();

    return ok ? 0 : 1;
}