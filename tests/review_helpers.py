"""Complete review payloads for tests that exercise decisions, not checklist validation."""
import json
import sqlite3


def review_payload(fixture, app, decision="valid"):
    with sqlite3.connect(fixture.storage / "app.sqlite") as db:
        rules, pathway = db.execute("SELECT rule_snapshot_json,pathway FROM applications WHERE id=?", (app,)).fetchone()
        latest = db.execute("SELECT documents_json FROM application_revisions WHERE application_id=? ORDER BY revision DESC LIMIT 1", (app,)).fetchone()
        documents = json.loads(latest[0]) if latest else {row[0]: row[1] for row in db.execute(
            "SELECT kind,id FROM application_documents WHERE application_id=? AND deleted_at IS NULL", (app,))}
    route = next(row for row in json.loads(rules)["pathways"] if row["code"] == pathway)
    result = {}
    for doc in route["documents"]:
        key = "doc_" + doc["code"]
        result[f"checklist[{key}][status]"] = "valid" if doc["code"] in documents else "not_applicable"
        result[f"checklist[{key}][note]"] = "Bukti diperiksa untuk pengujian."
    for key in ["identity", "residence", "eligibility", "consistency"]:
        result[f"checklist[criterion_{key}][status]"] = decision if key == "consistency" and decision in ["invalid", "needs_correction"] else "valid"
        result[f"checklist[criterion_{key}][note]"] = "Kriteria diperiksa untuk pengujian."
    return result
