"""
Builds the Chrome and Firefox versions of the extension from the one shared `extension/` folder.

    python3 tools/build.py            (from the treatwell-sync folder)

Writes salon-bookings-sync-chrome.zip and salon-bookings-sync-firefox.zip (files at the top, so
"Extract All" gives one folder), plus build/chrome and build/firefox for loading unpacked or testing.
salon-bookings-sync.zip stays as a copy of the Chrome version so older download links keep working.
"""
import json
import os
import shutil
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, "extension")
FIREFOX_ID = "salon-bookings-sync@deea0210.github.io"


def firefox_manifest(chrome):
    m = json.loads(json.dumps(chrome))
    # Firefox runs the background as an event page instead of a service worker.
    m["background"] = {"scripts": ["extract.js", "background.js"]}
    m.pop("minimum_chrome_version", None)
    m.pop("options_page", None)
    m["options_ui"] = {"page": "options.html", "open_in_tab": True}
    m["browser_specific_settings"] = {"gecko": {
        "id": FIREFOX_ID,
        "strict_min_version": "140.0",  # page scripts in the "MAIN" world and data consent need Firefox 140+
        # Shown when installing: the bookings (clients' names, phones, emails) can be sent to the salon software.
        "data_collection_permissions": {"required": ["personallyIdentifyingInfo", "websiteContent"]},
    }, "gecko_android": {"strict_min_version": "142.0"}}
    return m


def build(name, manifest):
    out = os.path.join(ROOT, "build", name)
    shutil.rmtree(out, ignore_errors=True)
    shutil.copytree(SRC, out, ignore=shutil.ignore_patterns("manifest.json", ".*"))
    with open(os.path.join(out, "manifest.json"), "w") as f:
        json.dump(manifest, f, indent=2)
        f.write("\n")
    zip_path = os.path.join(ROOT, f"salon-bookings-sync-{name}.zip")
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as z:
        for folder, _, files in sorted(os.walk(out)):
            for file in sorted(files):
                path = os.path.join(folder, file)
                z.write(path, os.path.relpath(path, out))
    return zip_path


def main():
    with open(os.path.join(SRC, "manifest.json")) as f:
        chrome = json.load(f)
    chrome_zip = build("chrome", chrome)
    firefox_zip = build("firefox", firefox_manifest(chrome))
    shutil.copyfile(chrome_zip, os.path.join(ROOT, "salon-bookings-sync.zip"))
    print(f"version {chrome['version']}: {os.path.basename(chrome_zip)}, {os.path.basename(firefox_zip)}")


if __name__ == "__main__":
    main()
