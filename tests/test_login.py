#!/usr/bin/env python3
"""Prueba el login, la sesión compartida y el CRUD a través del balanceador."""

from __future__ import annotations

import argparse
import json
import os
import sys
import uuid
from collections import Counter
from urllib.error import URLError
from urllib.parse import parse_qs, urlencode, urlsplit

from http_helpers import Client, Document, Form, TestFailure, check, expect_login_redirect, expect_success_redirect


class Integration:
    def __init__(self, args):
        self.args = args
        self.client = Client(args.base_url, args.timeout)
        self.user = os.environ.get("AGENDATEST_USER", "")
        self.password = os.environ.get("AGENDATEST_PASSWORD", "")
        check(bool(self.user) and bool(self.password), "Define AGENDATEST_USER y AGENDATEST_PASSWORD; nunca se imprimen ni se guardan")
        self.instances: Counter[str] = Counter()
        self.checks = 0

    def passed(self, message: str):
        self.checks += 1
        print(f"[OK] {message}", flush=True)

    def anonymous(self):
        for path in ("index.php", "agregar.php", "editar.php?id=0", "eliminar.php"):
            expect_login_redirect(self.client.request(path))
        if not self.args.read_only:
            for path in ("agregar.php", "editar.php?id=0", "eliminar.php"):
                expect_login_redirect(self.client.request(path, "POST", {}))
        self.passed("La agenda y todo el CRUD redirigen al login sin sesión")

    def login_form(self) -> Form:
        response = self.client.request("login.php")
        check(response.status == 200, f"No se pudo abrir login.php: HTTP {response.status}")
        return Document(response.body).form_with("password")

    def login(self):
        form = self.login_form()
        self.csrf_rejected("login.php", form, {"username": self.user, "password": "wrong-" + uuid.uuid4().hex})
        expect_login_redirect(self.client.request("index.php"))
        self.passed("El login rechaza tokens CSRF ausentes e incorrectos")

        fields = dict(form.fields, username=self.user, password="wrong-" + uuid.uuid4().hex)
        fields[form.csrf_field()] = form.fields[form.csrf_field()]
        response = self.client.submit("login.php", form, fields)
        check(response.status == 401, f"Contraseña incorrecta: se esperaba HTTP 401, llegó {response.status}")
        expect_login_redirect(self.client.request("index.php"))
        self.passed("Una contraseña incorrecta no concede acceso")

        form = self.login_form()
        previous_cookie = next((cookie.value for cookie in self.client.cookies if cookie.name == "agenda_session"), None)
        response = self.client.submit("login.php", form, dict(form.fields, username=self.user, password=self.password))
        expect_success_redirect(response)
        cookies = [cookie for cookie in self.client.cookies if cookie.name == "agenda_session"]
        check(len(cookies) == 1, "El login debe establecer una cookie agenda_session")
        cookie = cookies[0]
        check(cookie.value != previous_cookie, "El login debe rotar la sesión anónima")
        check("httponly" in {key.lower() for key in cookie._rest}, "La cookie de sesión debe ser HttpOnly")
        same_site = next((value for key, value in cookie._rest.items() if key.lower() == "samesite"), None)
        check(str(same_site).lower() == "lax", "La cookie de sesión debe usar SameSite=Lax")
        if urlsplit(self.args.base_url).scheme == "https":
            check(cookie.secure, "Con HTTPS la cookie debe ser Secure")
        self.passed("El login correcto rota la sesión y establece la cookie protegida")

    def read_protected(self, path: str = "index.php"):
        response = self.client.request(path)
        check(response.status == 200, f"La sesión se perdió en {urlsplit(response.url).path}: HTTP {response.status}")
        instance = response.instance
        self.instances[instance] += 1
        doc = Document(response.body)
        doc.logout_form()
        check(not any("password" in form.fields for form in doc.forms), "La respuesta protegida contiene el formulario de login")
        return response, doc

    def session_distribution(self):
        for number in range(self.args.samples):
            self.read_protected("index.php?" + urlencode({"balance_test": number}))
        check(len(self.instances) == self.args.expected_instances, f"Se esperaban {self.args.expected_instances} webs; se observaron {len(self.instances)}: {dict(self.instances)}")
        self.passed(f"Una misma sesión continúa en todas las webs: {dict(self.instances)}")

    def csrf_rejected(self, page_path: str, form: Form, fields: dict[str, str]):
        csrf = form.csrf_field()
        for label, token in (("ausente", None), ("incorrecto", "invalid-" + uuid.uuid4().hex)):
            data = dict(form.fields)
            data.update(fields)
            data.pop(csrf, None)
            if token is not None:
                data[csrf] = token
            response = self.client.submit(page_path, form, data)
            check(response.status == 403, f"{page_path}: CSRF {label} debe devolver HTTP 403, llegó {response.status}")

    def find_contact(self, marker: str):
        path = "index.php?" + urlencode({"buscar": marker})
        response, doc = self.read_protected(path)
        rows = [row for row in doc.rows if marker in row[0]]
        check(len(rows) == 1, f"Se esperaba exactamente un contacto de prueba con nombre {marker}")
        text, links, forms = rows[0]
        edit_links = [href for href in links if urlsplit(href).path.endswith("editar.php")]
        check(len(edit_links) == 1, "El contacto de prueba debe incluir un enlace para editar")
        identifiers = parse_qs(urlsplit(edit_links[0]).query).get("id", [])
        check(len(identifiers) == 1 and identifiers[0].isdigit() and int(identifiers[0]) > 0, "El contacto debe tener un identificador válido")
        deletes = [form for form in forms if urlsplit(form.action).path.endswith("eliminar.php")]
        check(len(deletes) == 1 and deletes[0].fields.get("id") == identifiers[0], "El formulario de borrado debe corresponder al contacto de prueba")
        return response.instance, int(identifiers[0]), edit_links[0], deletes[0], text

    def visible_everywhere(self, marker: str, identifier: int, required_values: tuple[str, ...]):
        seen = set()
        for _ in range(self.args.samples):
            instance, observed_id, _, _, text = self.find_contact(marker)
            check(observed_id == identifier, "Las webs muestran identificadores distintos para el mismo contacto")
            check(all(value in text for value in required_values), "Los datos del contacto no coinciden entre las webs")
            seen.add(instance)
            if len(seen) == self.args.expected_instances:
                break
        check(len(seen) == self.args.expected_instances, "No se pudo verificar el contacto compartido en todas las webs")

    def mutations(self):
        marker = "Prueba" + uuid.uuid4().hex[:12]
        fields = {"nombre": marker, "apellidos": "Balanceador", "telefono": "5550102000", "email": marker.lower() + "@example.test", "direccion": "Contacto temporal de integración"}
        created = False
        identifier = None
        try:
            response, doc = self.read_protected("agregar.php")
            form = doc.form_with("nombre")
            self.csrf_rejected("agregar.php", form, fields)
            self.passed("Crear rechaza tokens CSRF ausentes e incorrectos")
            expect_success_redirect(self.client.submit("agregar.php", form, dict(form.fields, **fields)))
            created = True
            _, identifier, edit_path, delete_form, _ = self.find_contact(marker)
            self.visible_everywhere(marker, identifier, (fields["telefono"], fields["email"]))
            self.passed("El contacto creado aparece con los mismos datos en las tres webs")

            _, edit_doc = self.read_protected(edit_path)
            edit_form = edit_doc.form_with("nombre")
            self.csrf_rejected(edit_path, edit_form, dict(fields, telefono="5550199999"))
            self.csrf_rejected("index.php", delete_form, {"id": str(identifier)})
            _, home_doc = self.read_protected()
            self.csrf_rejected("index.php", home_doc.logout_form(), {})
            check(self.client.request("eliminar.php").status == 405, "GET eliminar.php debe rechazar la operación con HTTP 405")
            check(self.client.request("logout.php").status == 405, "GET logout.php debe rechazar la operación con HTTP 405")
            self.visible_everywhere(marker, identifier, (fields["telefono"],))
            self.passed("Editar, borrar y cerrar sesión rechazan CSRF; GET no modifica datos ni cierra sesión")

            updated = dict(fields, telefono="5550102001", direccion="Contacto temporal editado")
            expect_success_redirect(self.client.submit(edit_path, edit_form, dict(edit_form.fields, **updated)))
            self.visible_everywhere(marker, identifier, (updated["telefono"], updated["direccion"]))
            self.passed("La edición aparece en todas las webs con la misma sesión")

            _, _, _, delete_form, _ = self.find_contact(marker)
            expect_success_redirect(self.client.submit("index.php", delete_form, dict(delete_form.fields)))
            seen = set()
            for _ in range(self.args.samples):
                response, doc = self.read_protected("index.php?" + urlencode({"buscar": marker}))
                check(not any(marker in row[0] for row in doc.rows), "El contacto borrado sigue apareciendo")
                seen.add(response.instance)
                if len(seen) == self.args.expected_instances:
                    break
            check(len(seen) == self.args.expected_instances, "No se pudo verificar el borrado en todas las webs")
            created = False
            self.passed("El borrado elimina el contacto temporal de la agenda compartida")
        finally:
            if created:
                try:
                    _, observed_id, _, delete_form, _ = self.find_contact(marker)
                    check(identifier is None or observed_id == identifier, "La limpieza no puede borrar otro contacto")
                    expect_success_redirect(self.client.submit("index.php", delete_form, dict(delete_form.fields)))
                    print(f"[LIMPIEZA] Contacto temporal {marker} eliminado", flush=True)
                except (TestFailure, URLError, TimeoutError, OSError) as error:
                    print(f"[LIMPIEZA FALLIDA] Revisar solamente contacto {marker}, id={identifier}: {error}", file=sys.stderr)

    def logout(self):
        _, doc = self.read_protected()
        old_session = self.client.clone()
        form = doc.logout_form()
        response = self.client.submit("index.php", form, dict(form.fields))
        expect_login_redirect(response)
        expect_login_redirect(self.client.request("index.php"))
        seen = set()
        for number in range(self.args.samples):
            # Una respuesta puede reemplazar la cookie invalidada por una sesión
            # anónima. Reenviar la instantánea original en cada petición.
            response = old_session.clone().request("index.php?" + urlencode({"replay_test": number}))
            expect_login_redirect(response)
            seen.add(response.instance)
            if len(seen) == self.args.expected_instances:
                break
        check(len(seen) == self.args.expected_instances, "No se pudo probar la cookie antigua contra todas las webs")
        self.passed("Cerrar sesión invalida la cookie antigua en todas las webs, incluso al reenviarla")

    def run(self):
        self.anonymous()
        self.login()
        self.session_distribution()
        if self.args.read_only:
            self.passed("Modo de consulta: no se crean, editan ni borran contactos")
        else:
            self.mutations()
        self.logout()
        print(json.dumps({"result": "ok", "checks": self.checks, "instances": dict(self.instances), "read_only": self.args.read_only}, ensure_ascii=False))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default=os.environ.get("AGENDA_BASE_URL"), help="URL del balanceador; también AGENDA_BASE_URL")
    parser.add_argument("--read-only", action="store_true", help="Solo login, consultas y logout; no modifica contactos")
    parser.add_argument("--expected-instances", type=int, default=3, help="Webs que deben responder (2 para probar failover)")
    parser.add_argument("--samples", type=int, default=24, help="Peticiones máximas por comprobación de distribución")
    parser.add_argument("--timeout", type=float, default=15.0, help="Timeout por petición en segundos")
    args = parser.parse_args()
    if not args.base_url:
        parser.error("Indica --base-url o define AGENDA_BASE_URL")
    if args.expected_instances < 1 or args.samples < args.expected_instances or args.timeout <= 0:
        parser.error("Usa expected-instances >= 1, samples >= expected-instances y timeout > 0")
    try:
        Integration(args).run()
    except (TestFailure, URLError, TimeoutError, OSError) as error:
        print(f"[FALLO] {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
