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
    root["source"] = "gcs-export";

    // Load FPP settings (required)
    LoadSettings("/home/fpp/media", false);

    // Pull values directly from FPP settings
    std::string latStr = getSetting("Latitude");
    std::string lonStr = getSetting("Longitude");
    std::string tz     = getSetting("TimeZone");

    double lat = latStr.empty() ? 0.0 : atof(latStr.c_str());
    double lon = lonStr.empty() ? 0.0 : atof(lonStr.c_str());

    root["latitude"]  = lat;
    root["longitude"] = lon;
    root["timezone"]  = tz;

    // Pull locale (holidays, locale name, etc.)
    Json::Value locale = LocaleHolder::GetLocale();
    root["rawLocale"] = locale;

    bool ok = true;
    std::string error;

    if (lat == 0.0 || lon == 0.0) {
        ok = false;
        error = "Latitude/Longitude not present (or zero) in FPP settings.";
    }

    root["ok"] = ok;
    if (!ok) {
        root["error"] = error;
        std::cerr << "WARN: " << error << std::endl;
    }

    // Write output file
    std::ofstream out(OUTPUT_PATH);
    if (!out) {
        std::cerr << "ERROR: Unable to write " << OUTPUT_PATH << std::endl;
        return 2;
    }

    out << root.toStyledString();
    out.close();

    return ok ? 0 : 1;
}
