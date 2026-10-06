"""Cliente HTTP y lectura de formularios para las pruebas, sin dependencias."""

from __future__ import annotations

import copy
from dataclasses import dataclass, field
from html.parser import HTMLParser
from http.cookiejar import CookieJar
from urllib.error import HTTPError
from urllib.parse import urlencode, urljoin, urlsplit
from urllib.request import HTTPRedirectHandler, HTTPCookieProcessor, Request, build_opener


class TestFailure(Exception):
    """Una expectativa del despliegue no se cumplió."""


def check(condition: bool, message: str) -> None:
    if not condition:
        raise TestFailure(message)


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


@dataclass
class Response:
    status: int
    headers: object
    body: str
    url: str

    @property
    def instance(self) -> str:
        value = self.headers.get("X-App-Instance", "").strip()
        check(bool(value), f"{urlsplit(self.url).path}: falta X-App-Instance")
        return value


@dataclass
class Form:
    action: str
    method: str
    fields: dict[str, str] = field(default_factory=dict)
    types: dict[str, str] = field(default_factory=dict)

    def csrf_field(self) -> str:
        names = [name for name in self.fields if "csrf" in name.lower()]
        check(len(names) == 1, "El formulario debe incluir un único campo CSRF")
        name = names[0]
        check(bool(self.fields[name]), "El token CSRF no debe estar vacío")
        return name


class Document(HTMLParser):
    def __init__(self, html: str):
        super().__init__(convert_charrefs=True)
        self.forms: list[Form] = []
        self.links: list[str] = []
        self.rows: list[tuple[str, list[str], list[Form]]] = []
        self._form: Form | None = None
        self._row: tuple[list[str], list[str], list[Form]] | None = None
        self.feed(html)

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]):
        attributes = dict(attrs)
        if tag == "tr":
            self._row = ([], [], [])
        elif tag == "a" and attributes.get("href"):
            href = attributes["href"]
            self.links.append(href)
            if self._row is not None:
                self._row[1].append(href)
        elif tag == "form":
            self._form = Form(attributes.get("action") or "", (attributes.get("method") or "get").upper())
            self.forms.append(self._form)
            if self._row is not None:
                self._row[2].append(self._form)
        elif tag == "input" and self._form is not None and attributes.get("name"):
            name = attributes["name"]
            self._form.fields[name] = attributes.get("value") or ""
            self._form.types[name] = attributes.get("type") or "text"

    def handle_data(self, data: str):
        if self._row is not None:
            self._row[0].append(data)

    def handle_endtag(self, tag: str):
        if tag == "form":
            self._form = None
        elif tag == "tr" and self._row is not None:
            texts, links, forms = self._row
            self.rows.append((" ".join(texts), links, forms))
            self._row = None

    def form_with(self, field_name: str) -> Form:
        forms = [form for form in self.forms if field_name in form.fields]
        check(len(forms) == 1, f"Se esperaba un formulario con el campo {field_name}")
        return forms[0]

    def logout_form(self) -> Form:
        forms = [form for form in self.forms if urlsplit(form.action).path.endswith("logout.php")]
        check(len(forms) == 1, "Se esperaba un formulario para cerrar sesión")
        return forms[0]


class Client:
    def __init__(self, base_url: str, timeout: float = 15.0, cookies: CookieJar | None = None):
        parts = urlsplit(base_url)
        check(parts.scheme in ("http", "https") and bool(parts.hostname), "La URL debe usar http:// o https://")
        check(not parts.username and not parts.password and not parts.query and not parts.fragment, "La URL base no debe incluir credenciales, query ni fragmento")
        self.base_url = base_url.rstrip("/") + "/"
        self.timeout = timeout
        self.cookies = cookies if cookies is not None else CookieJar()
        self.opener = build_opener(NoRedirect(), HTTPCookieProcessor(self.cookies))

    def clone(self) -> "Client":
        jar = CookieJar()
        for cookie in self.cookies:
            jar.set_cookie(copy.copy(cookie))
        return Client(self.base_url, self.timeout, jar)

    def resolve(self, path: str) -> str:
        resolved = urljoin(self.base_url, path)
        origin = urlsplit(self.base_url)
        target = urlsplit(resolved)
        check((origin.scheme, origin.netloc) == (target.scheme, target.netloc), "El formulario o ruta apunta fuera del origen de prueba")
        return resolved

    def request(self, path: str, method: str = "GET", data: dict[str, str] | None = None) -> Response:
        body = urlencode(data).encode("utf-8") if data is not None else None
        request = Request(self.resolve(path), data=body, method=method, headers={"User-Agent": "Cloud-1-integration-tests/1.0", "Cache-Control": "no-cache"})
        try:
            raw = self.opener.open(request, timeout=self.timeout)
        except HTTPError as error:
            raw = error
        with raw:
            return Response(raw.status, raw.headers, raw.read().decode("utf-8", errors="replace"), raw.url)

    def submit(self, page_path: str, form: Form, fields: dict[str, str]) -> Response:
        check(form.method == "POST", "Las operaciones de autenticación y CRUD deben usar POST")
        action = urljoin(self.resolve(page_path), form.action) if form.action else page_path
        return self.request(action, method="POST", data=fields)


def expect_login_redirect(response: Response) -> None:
    check(response.status in (302, 303), f"Acceso anónimo: se esperaba redirección, llegó HTTP {response.status}")
    location = response.headers.get("Location", "")
    check(urlsplit(location).path.endswith("login.php"), "El acceso anónimo debe redirigir a login.php")


def expect_success_redirect(response: Response) -> None:
    check(response.status in (302, 303), f"Operación: se esperaba redirección, llegó HTTP {response.status}")
    check(urlsplit(response.headers.get("Location", "")).path.endswith("index.php"), "La operación debe regresar a index.php")
