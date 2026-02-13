import requests
import json

AMAP_KEY = "363daac5191aebe208cb0e73a3ed6761"

def address_to_lng_lat_list(address, max_results=10, city=None):
    url = "https://restapi.amap.com/v3/geocode/geo"
    params = {
        "key": AMAP_KEY,
        "address": address
    }

    if city:
        params["city"] = city

    resp = requests.get(url, params=params, timeout=5)
    data = resp.json()

    print("\n--- RAW API RESPONSE ---")
    print(json.dumps(data, indent=2, ensure_ascii=False))

    if data.get("status") != "1":
        print("❌ API ERROR:", data.get("info"))
        return []

    results = []
    for geocode in data.get("geocodes", []):
        location = geocode.get("location")
        if not location:
            continue
        parts = location.split(",")
        if len(parts) != 2:
            continue
        lng, lat = parts[0].strip(), parts[1].strip()
        results.append((lng, lat))

    return results[:max_results]


if __name__ == "__main__":
    address = " 北京市东城区东华门街道天安门"
    coords = address_to_lng_lat_list(address)

    print("\nCoordinate suggestions:")
    for i, (lng, lat) in enumerate(coords, 1):
        print(f"{i}. {lng}, {lat}")

    input("\nPress ENTER to exit...")
