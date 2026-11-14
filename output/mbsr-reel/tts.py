import asyncio
import json
import sys
from pathlib import Path

import edge_tts

ROOT = Path(__file__).parent
VOICE = sys.argv[1] if len(sys.argv) > 1 else "fa-IR-DilaraNeural"
RATE = sys.argv[2] if len(sys.argv) > 2 else "-6%"

LINES = json.loads((ROOT / "script.json").read_text(encoding="utf-8"))


async def synth(item):
    comm = edge_tts.Communicate(item["tts"], VOICE, rate=item.get("rate", RATE), pitch="-2Hz", boundary="WordBoundary")
    audio = bytearray()
    words = []
    async for chunk in comm.stream():
        if chunk["type"] == "audio":
            audio.extend(chunk["data"])
        elif chunk["type"] == "WordBoundary":
            words.append({
                "start": chunk["offset"] / 1e7,
                "end": (chunk["offset"] + chunk["duration"]) / 1e7,
                "text": chunk["text"],
            })
    out = ROOT / "audio" / f"vo_{item['id']}.mp3"
    out.parent.mkdir(exist_ok=True)
    out.write_bytes(bytes(audio))
    (ROOT / "audio" / f"vo_{item['id']}.words.json").write_text(
        json.dumps(words, ensure_ascii=False, indent=1), encoding="utf-8")
    print(item["id"], "words:", len(words), "last end:", round(words[-1]["end"], 2) if words else None)


async def main():
    only = set(sys.argv[3:])
    for item in LINES:
        if not only or item["id"] in only:
            await synth(item)


asyncio.run(main())
