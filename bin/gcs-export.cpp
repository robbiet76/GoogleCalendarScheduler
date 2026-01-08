#include <iostream>
#include <fstream>
#include <string>

#include <jsoncpp/json/json.h>

#include "settings.h"
#include "FPPLocale.h"

static const char* OUTPUT_PATH =
    "/home/fpp/media/plugins/GoogleCalendarScheduler/runtime/fpp-env.json";

int main() {
    Json::Value root;
    root["schemaVersion"] = 1;
    root["source"] = "gcs-export";

    // ---------------------------------------------------------------------
    // Load FPP settings (required for TimeZone)
    // ---------------------------------------------------------------------
    LoadSettings("/home/fpp/media", false);

    // ---------------------------------------------------------------------
    // Timezone comes from FPP settings
    // ---------------------------------------------------------------------
    std::string tz = getSetting("TimeZone");
    root["timezone"] = tz;

    // ---------------------------------------------------------------------
    // Locale data (canonical source of latitude, longitude, holidays, locale)
    // ---------------------------------------------------------------------
    Json::Value locale = LocaleHolder::GetLocale();
    root["rawLocale"] = locale;

    double lat = 0.0;
    double lon = 0.0;

    if (locale.isObject()) {
        if (locale.isMember("latitude") && locale["latitude"].isNumeric()) {
            lat = locale["latitude"].asDouble();
        }
        if (locale.isMember("longitude") && locale["longitude"].isNumeric()) {
            lon = locale["longitude"].asDouble();
        }
    }

    root["latitude"]  = lat;
    root["longitude"] = lon;

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------
    bool ok = true;

    if (lat == 0.0 || lon == 0.0) {
        ok = false;
        root["error"] =
            "Latitude/Longitude not present (or zero) in FPP locale.";
        std::cerr
            << "WARN: Latitude/Longitude not present (or zero) in FPP locale."
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
    // Write output
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