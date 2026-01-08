#include <iostream>
#include <fstream>
#include <string>
#include <cstdlib>

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
    // Load FPP settings (REQUIRED)
    // ---------------------------------------------------------------------
    LoadSettings("/home/fpp/media", false);

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
    // Locale data (holidays, locale name, etc.)
    // ---------------------------------------------------------------------
    Json::Value locale = LocaleHolder::GetLocale();
    root["rawLocale"] = locale;

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