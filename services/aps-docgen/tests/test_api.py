"""Tests for the HTTP layer: auth, payload shapes and error mapping.

Test-only; see the note at the top of test_render.py about fixtures.
"""

from __future__ import annotations

import io
import json

import pytest
from docx import Document
from fastapi.testclient import TestClient

import app as app_module
from tests.test_render import JOB_TOKENS, make_docx, make_docx_table, text_of

KEY = "test-key-not-a-real-secret"


@pytest.fixture()
def client(monkeypatch):
    monkeypatch.setenv("APS_DOCGEN_KEY", KEY)
    return TestClient(app_module.app)


def post_render(client, template: bytes, data, key: str | None = KEY):
    headers = {"X-APS-Key": key} if key is not None else {}
    return client.post(
        "/render",
        files={"file": ("template.docx", template, "application/octet-stream")},
        data={"data": json.dumps(data)},
        headers=headers,
    )


def test_health_reports_configuration(client):
    response = client.get("/health")

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ok"
    assert body["key_configured"] is True
    # The key itself must never appear in a response.
    assert KEY not in response.text


def test_render_returns_merged_docx(client):
    template = make_docx(["Client: {{ Client }}", "Email: {{ Client.Mail }}"])
    response = post_render(client, template, JOB_TOKENS)

    assert response.status_code == 200
    assert response.headers["content-type"].startswith(
        "application/vnd.openxmlformats-officedocument"
    )
    out = text_of(response.content)
    assert "Client: Example Statutory Authority" in out
    assert "Email: notices@example.test" in out


def test_render_accepts_object_payload_with_tables(client):
    template = make_docx_table([
        ["{%tr for fee in Job_Fees %}", ""],
        ["{{ fee.Description }}", "{{ fee.Amount }}"],
        ["{%tr endfor %}", ""],
    ])
    payload = {
        "tokens": JOB_TOKENS,
        "tables": {"Job_Fees": [{"Description": "Delivery Fee", "Amount": "35.00"}]},
    }
    response = post_render(client, template, payload)

    assert response.status_code == 200
    out = text_of(response.content)
    assert "Delivery Fee" in out
    assert "35.00" in out


def test_render_accepts_object_payload_with_lists(client):
    template = make_docx(["{{ Documents.Affidavit_Template }}"])
    payload = {
        "tokens": JOB_TOKENS,
        "lists": {
            "Documents.Affidavit_Template": [
                "Example Affidavit of Service",
                "Example Application for Minor Civil Dispute",
            ]
        },
    }
    response = post_render(client, template, payload)

    assert response.status_code == 200
    out = text_of(response.content)
    assert "Example Affidavit of Service" in out
    assert "Example Application for Minor Civil Dispute" in out


def test_list_given_as_a_string_is_400_not_one_row_per_letter(client):
    """A scalar where an array belongs would otherwise iterate per character."""
    template = make_docx(["{{ Documents.Affidavit_Template }}"])
    payload = {
        "tokens": JOB_TOKENS,
        "lists": {"Documents.Affidavit_Template": "Example Affidavit of Service"},
    }
    response = post_render(client, template, payload)

    assert response.status_code == 400
    assert "Documents.Affidavit_Template" in response.json()["detail"]


def test_payload_without_lists_still_works(client):
    """Drupal not yet updated must keep merging exactly as before."""
    template = make_docx(["Client: {{ Client }}"])
    response = post_render(client, template, {"tokens": JOB_TOKENS})

    assert response.status_code == 200
    assert "Client: Example Statutory Authority" in text_of(response.content)


def test_missing_key_is_rejected(client):
    template = make_docx(["{{ Client }}"])
    response = post_render(client, template, JOB_TOKENS, key=None)

    assert response.status_code == 401


def test_wrong_key_is_rejected(client):
    template = make_docx(["{{ Client }}"])
    response = post_render(client, template, JOB_TOKENS, key="wrong")

    assert response.status_code == 401


def test_unconfigured_service_refuses_rather_than_allowing(monkeypatch):
    """A misdeployed service must fail closed, not accept everything."""
    monkeypatch.delenv("APS_DOCGEN_KEY", raising=False)
    unconfigured = TestClient(app_module.app)

    response = post_render(unconfigured, make_docx(["{{ Client }}"]), JOB_TOKENS, key="anything")

    assert response.status_code == 503
    assert "not configured" in response.json()["detail"]


def test_bad_template_is_422_not_500(client):
    """An authoring mistake is the caller's to fix, so it is not a server error."""
    response = post_render(client, b"not a docx at all", JOB_TOKENS)

    assert response.status_code == 422
    assert "not a Word .docx file" in response.json()["detail"]


def test_malformed_json_is_400(client):
    template = make_docx(["{{ Client }}"])
    response = client.post(
        "/render",
        files={"file": ("template.docx", template, "application/octet-stream")},
        data={"data": "{not json"},
        headers={"X-APS-Key": KEY},
    )

    assert response.status_code == 400
    assert "not valid JSON" in response.json()["detail"]


def test_empty_upload_is_400(client):
    response = client.post(
        "/render",
        files={"file": ("template.docx", b"", "application/octet-stream")},
        data={"data": json.dumps(JOB_TOKENS)},
        headers={"X-APS-Key": KEY},
    )

    assert response.status_code == 400


def test_oversized_template_is_413(client, monkeypatch):
    monkeypatch.setattr(app_module, "MAX_TEMPLATE_BYTES", 100)
    template = make_docx(["{{ Client }}"])

    response = post_render(client, template, JOB_TOKENS)

    assert response.status_code == 413


def test_shadowing_violation_is_400_not_422(client):
    """A bad token NAME is a Drupal-side contract break, not an authoring one."""
    template = make_docx(["{{ Client }}"])
    response = post_render(client, template, [{"name": "Client.title", "value": "x"}])

    assert response.status_code == 400
    assert "shadowed" in response.json()["detail"]


def test_request_id_is_echoed(client):
    template = make_docx(["{{ Client }}"])
    response = client.post(
        "/render",
        files={"file": ("template.docx", template, "application/octet-stream")},
        data={"data": json.dumps(JOB_TOKENS)},
        headers={"X-APS-Key": KEY, "X-Request-Id": "abc-123"},
    )

    assert response.status_code == 200
    assert response.headers["X-Request-Id"] == "abc-123"


def test_merged_output_is_a_readable_document(client):
    """Guard against returning bytes Word cannot open."""
    template = make_docx(["{{ Client }}"])
    response = post_render(client, template, JOB_TOKENS)

    document = Document(io.BytesIO(response.content))
    assert any("Example Statutory Authority" in p.text for p in document.paragraphs)
