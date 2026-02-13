import requests
import json

AMAP_KEY = "363daac5191aebe208cb0e73a3ed6761"  # ⚠️ NOT JS KEY

def lng_lat_to_address_list(lng, lat, max_results=10):
    url = "https://restapi.amap.com/v3/geocode/regeo"
    params = {
        "key": AMAP_KEY,
        "location": f"{lng},{lat}",
        "extensions": "all",
        "radius": 1000
    }

    resp = requests.get(url, params=params, timeout=5)
    data = resp.json()

    # 🔎 DEBUG OUTPUT
    print("\n--- RAW API RESPONSE ---")
    print(json.dumps(data, indent=2, ensure_ascii=False))

    if data.get("status") != "1":
        print("❌ API ERROR:", data.get("info"))
        return []

    results = []
    regeocode = data.get("regeocode", {})

    # Full address
    if regeocode.get("formatted_address"):
        results.append(regeocode["formatted_address"])

    # POIs
    for poi in regeocode.get("pois", []):
        name = poi.get("name")
        address = poi.get("address", "")
        if name:
            results.append(f"{name} {address}".strip())

    return results[:max_results]


# ✅ TEST (AMap-valid GCJ-02 coords)
if __name__ == "__main__":
    lng = 116.397428
    lat = 39.90923

    addresses = lng_lat_to_address_list(lng, lat)

    print("\nAddress suggestions:")
    for i, a in enumerate(addresses, 1):
        print(f"{i}. {a}")

    input("\nPress ENTER to exit...")
