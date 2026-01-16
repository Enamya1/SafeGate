from fastapi import FastAPI

from app.api.router import router as api_router

app = FastAPI(title="SafeGate Python Service")


@app.get("/health")
def health() -> dict:
    return {"status": "ok"}


app.include_router(api_router, prefix="/api")

