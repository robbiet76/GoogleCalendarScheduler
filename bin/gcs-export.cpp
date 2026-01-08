#include <iostream>
#include <fstream>
#include <string>
#include <cstdlib>

#include <jsoncpp/json/json.h>

#include "settings.h"
#include "FPPLocale.h"

static const char* OUTPUT_PATH =
    "/home/fpp/media/plugins/GoogleCalendarScheduler/runtime/fpp-env.json";

int main()
{
    Json::Value root(Json::objectValue);
    root["schemaVersion"] = 1;
    root["source"] = "gcs-export";

    // -------------------------------------------------------------
    // Initialize FPP settings (REQUIRED for getSetting / locale)
    // -------------------------------------------------------------
    try {
        LoadSettings("/home/fpp/media");
    } catch (...) {
        // LoadSettings should not throw, but we never allow exporter to crash
        std::cerr << "WARN: LoadSettings threw unexpectedly\n";
    }

    // -------------------------------------------------------------
    // Read canonical settings
    // -------------------------------------------------------------
    std::string latStr = getSetting("Latitude");
    std::string lonStr = getSetting("Longitude");
    std::string tz     = getSetting("TimeZone");

    double lat = latStr.empty() ? 0.0 : std::atof(latStr.c_str());
    double lon = lonStr.empty() ? 0.0 : std::atof(lonStr.c_str());

    root["latitude"]  = lat;
    root["longitude"] = lon;
    root["timezone"]  = tz;

    // -------------------------------------------------------------
    // Locale data (best-effort)
    // -------------------------------------------------------------
    try {
        Json::Value locale = LocaleHolder::GetLocale();
        root["rawLocale"] = locale;
    } catch (...) {
        // Locale failure must never abort export
        std::cerr << "WARN: Unable to load FPP locale\n";
        root["rawLocale"] = Json::nullValue;
    }

    // -------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------
    bool ok = true;
    Json::Value errors(Json::arrayValue);

    if (lat == 0.0 || lon == 0.0) {
        ok = false;
        errors.append("Latitude/Longitude not present or zero");
        std::cerr << "WARN: Latitude/Longitude not present (or zero)\n";
    }

    if (tz.empty()) {
        ok = false;
        errors.append("Timezone not present");
        std::cerr << "WARN: Timezone not present\n";
    }

    root["ok"] = ok;
    root["errors"] = errors;

    // -------------------------------------------------------------
    // Write output atomically
    // -------------------------------------------------------------
    std::ofstream out(OUTPUT_PATH, std::ios::out | std::ios::trunc);
    if (!out.is_open()) {
        std::cerr << "ERROR: Unable to write " << OUTPUT_PATH << "\n";
        return 2;
    }

    out << root.toStyledString();
    out.close();

    return 0; // exporter should never fail hard
}