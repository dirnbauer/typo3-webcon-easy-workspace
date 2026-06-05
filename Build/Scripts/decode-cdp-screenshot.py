#!/usr/bin/env python3
"""Decode a CDP Page.captureScreenshot JSON log to PNG."""
from __future__ import annotations

import argparse
import base64
import json
import sys
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("json_path", type=Path)
    parser.add_argument("output_path", type=Path)
    args = parser.parse_args()

    payload = json.loads(args.json_path.read_text())
    data = payload.get("data") or payload.get("result", {}).get("data")
    if not data:
        print(f"No image data in {args.json_path}", file=sys.stderr)
        return 1

    raw = base64.b64decode(data)
    if len(raw) < 100:
        print(f"Decoded image too small ({len(raw)} bytes)", file=sys.stderr)
        return 1

    args.output_path.parent.mkdir(parents=True, exist_ok=True)
    args.output_path.write_bytes(raw)
    print(f"Wrote {args.output_path} ({len(raw)} bytes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
