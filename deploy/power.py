#!/usr/bin/env python3
"""Enciende/apaga exclusivamente las tres instancias guardadas en el inventario local."""
import argparse
import json
import subprocess
from pathlib import Path

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("action", choices=["START", "SOFTSTOP", "STOP"])
parser.add_argument("--inventory", type=Path, default=Path(__file__).resolve().parents[1] / ".local/instances.json")
args = parser.parse_args()
inventory = json.loads(args.inventory.read_text())
nodes = inventory["nodes"]
if len(nodes) != 3 or {n["name"] for n in nodes} != {"FrontEnd", "BackEnd", "BaseDatos"}:
    parser.error("El inventario debe contener exactamente FrontEnd, BackEnd y BaseDatos.")
order = {"FrontEnd": 0, "BackEnd": 1, "BaseDatos": 2}
for node in sorted(nodes, key=lambda n: order[n["name"]], reverse=args.action == "START"):
    result = subprocess.run([
        "oci", "compute", "instance", "action", "--profile", inventory["profile"],
        "--region", inventory["region"], "--auth", "api_key", "--instance-id", node["instance_id"],
        "--action", args.action, "--query", 'data."lifecycle-state"', "--raw-output", "--max-retries", "0"
    ], capture_output=True, text=True)
    if result.returncode:
        raise SystemExit(f"{node['name']}: {result.stderr.strip()}")
    print(f"{node['name']}: {result.stdout.strip()}", flush=True)
