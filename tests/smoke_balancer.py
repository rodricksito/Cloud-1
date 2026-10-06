#!/usr/bin/env python3
"""Comprueba salud y reparto de peticiones sin credenciales ni cambios en la agenda."""

from __future__ import annotations

import argparse
import json
import os
import sys
from collections import Counter
from urllib.error import URLError
from urllib.parse import urlencode

from http_helpers import Client, Document, TestFailure, check


def run(args):
    client = Client(args.base_url, args.timeout)
    observed: dict[str, Counter[str]] = {"login.php": Counter(), "health.php": Counter()}
    for path in observed:
        for number in range(args.samples):
            response = client.request(path + "?" + urlencode({"balance_test": number}))
            check(response.status == 200, f"{path}: HTTP {response.status}; se esperaba 200")
            instance = response.instance
            observed[path][instance] += 1
            if path == "login.php":
                form = Document(response.body).form_with("password")
                form.csrf_field()
            else:
                try:
                    health = json.loads(response.body)
                except json.JSONDecodeError as error:
                    raise TestFailure("health.php debe devolver JSON") from error
                check(isinstance(health, dict) and health.get("status") == "ok", "El health de la web no está ok")
                check(health.get("instance") == instance, "El health y X-App-Instance identifican webs diferentes")
                backend = health.get("backend", {})
                check(isinstance(backend, dict) and backend.get("status") == "ok" and backend.get("database") == "ok", "La web no confirma backend y base de datos saludables")
    for path, instances in observed.items():
        check(len(instances) == args.expected_instances, f"{path}: se esperaban {args.expected_instances} webs, se observaron {dict(instances)}")
        if args.expect:
            check(set(instances) == set(args.expect), f"{path}: instancias observadas diferentes a --expect")
        print(f"[OK] {path}: {dict(instances)}", flush=True)
    print(json.dumps({"result": "ok", "distribution": {path: dict(instances) for path, instances in observed.items()}}, ensure_ascii=False))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default=os.environ.get("AGENDA_BASE_URL"), help="URL del balanceador; también AGENDA_BASE_URL")
    parser.add_argument("--expected-instances", type=int, default=3, help="Número esperado de webs (2 durante failover)")
    parser.add_argument("--expect", nargs="+", help="Nombres exactos esperados en X-App-Instance")
    parser.add_argument("--samples", type=int, default=18, help="Número de peticiones por ruta")
    parser.add_argument("--timeout", type=float, default=15.0, help="Timeout por petición en segundos")
    args = parser.parse_args()
    if not args.base_url:
        parser.error("Indica --base-url o define AGENDA_BASE_URL")
    if args.expected_instances < 1 or args.samples < args.expected_instances or args.timeout <= 0:
        parser.error("Usa expected-instances >= 1, samples >= expected-instances y timeout > 0")
    if args.expect and len(set(args.expect)) != args.expected_instances:
        parser.error("--expect debe contener exactamente expected-instances nombres distintos")
    try:
        run(args)
    except (TestFailure, URLError, TimeoutError, OSError) as error:
        print(f"[FALLO] {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
