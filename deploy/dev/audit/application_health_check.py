#!/usr/bin/env python3
"""Whole-application static health checks that need no PHP binary and no database.

Covers the cross-cutting invariants the feature-level audits do not:
  * migrations are replayable from scratch (no duplicate table creation, no ALTER
    before the table exists, every migration has a down()),
  * every command wired into routes/console.php actually exists,
  * every Eloquent relationship points at a model that exists,
  * no debugging helpers (dd/dump/var_dump/die) left in application code,
  * no hard-coded secrets — credentials must come from env(),
  * .env and other local-only files are not tracked,
  * PHP files parse (needs php-parser via routes_integrity_check.js — skipped here).

Run: python3 deploy/dev/audit/application_health_check.py
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]


def php_files(*dirs):
    for d in dirs:
        yield from sorted((ROOT / d).rglob("*.php"))


class MigrationIntegrityTest(unittest.TestCase):
    def setUp(self):
        self.migrations = sorted((ROOT / "database/migrations").glob("*.php"))
        self.created = {}
        self.problems = []
        for path in self.migrations:
            source = path.read_text(errors="ignore")
            if "function down(" not in source:
                self.problems.append(f"{path.name}: no down() method")
            for table in re.findall(r"Schema::create\(\s*'([^']+)'", source):
                if table in self.created:
                    self.problems.append(
                        f"{path.name}: creates '{table}' twice (also {self.created[table]})"
                    )
                else:
                    self.created[table] = path.name
            for table in re.findall(r"Schema::table\(\s*'([^']+)'", source):
                if table not in self.created:
                    self.problems.append(f"{path.name}: alters '{table}' before it is created")

    def test_migrations_exist(self):
        self.assertGreater(len(self.migrations), 100)

    def test_migrations_replay_cleanly_in_order(self):
        self.assertEqual(self.problems, [], "\n".join(self.problems))


class ConsoleScheduleTest(unittest.TestCase):
    SCHEDULE = (ROOT / "routes/console.php").read_text()

    def test_every_scheduled_command_is_declared(self):
        declared = set()
        for path in php_files("app/Console/Commands"):
            for signature in re.findall(r"protected\s+\$signature\s*=\s*'([^']+)'", path.read_text(errors="ignore")):
                declared.add(signature.split()[0])
        missing = [
            name for name in re.findall(r"Schedule::command\('([a-z0-9:\-]+)", self.SCHEDULE)
            if name not in declared and not name.startswith("model:")
        ]
        self.assertEqual(missing, [], f"scheduled but not declared: {missing}")

    def test_schedule_has_no_duplicate_entries(self):
        # Compare the whole command line: `pms:crm-run` and `pms:crm-run --journeys`
        # are different jobs, the same line registered twice is a duplicate.
        entries = re.findall(r"Schedule::command\('([^']+)'", self.SCHEDULE)
        duplicates = sorted({e for e in entries if entries.count(e) > 1})
        self.assertEqual(duplicates, [], f"registered more than once: {duplicates}")


class ModelRelationTest(unittest.TestCase):
    def test_relationship_targets_exist(self):
        models = {path.stem for path in php_files("app/Models")}
        unknown = []
        for path in php_files("app"):
            source = path.read_text(errors="ignore")
            for rel, target in re.findall(
                r"(belongsTo|hasMany|hasOne|belongsToMany|morphMany|morphTo|hasManyThrough)\s*\(\s*([A-Z]\w*)::class",
                source,
            ):
                if target not in models:
                    unknown.append(f"{path.relative_to(ROOT)}: {rel}({target}::class)")
        self.assertEqual(unknown, [], "\n".join(unknown))


class DebugHelperTest(unittest.TestCase):
    PATTERN = re.compile(r"(?<![\w>$])(dd|dump|var_dump|var_export|xdebug_break)\s*\(")

    def test_no_debug_helpers_in_application_code(self):
        hits = []
        for path in php_files("app", "routes"):
            for number, line in enumerate(path.read_text(errors="ignore").splitlines(), 1):
                if line.lstrip().startswith(("*", "//", "#")):
                    continue
                if self.PATTERN.search(line) and "function dump" not in line:
                    hits.append(f"{path.relative_to(ROOT)}:{number}: {line.strip()[:120]}")
        self.assertEqual(hits, [], "\n".join(hits))


class SecretTest(unittest.TestCase):
    """Credentials belong in env(), never in a literal.

    Only values that look like real credentials are flagged — a random-looking
    run of 20+ characters with mixed letters and digits, or a known key prefix.
    Validation rules ("required|string"), route constraints ("[A-Za-z0-9]{40}")
    and UI labels are therefore not reported.
    """

    ASSIGNMENT = re.compile(
        r"""['"](?:api[_-]?key|secret|token|password|passwd|private[_-]?key|client[_-]?secret)['"]\s*(?:=>|=)\s*['"]([^'"\s]{16,})['"]""",
        re.IGNORECASE,
    )
    LOOKS_LIKE_KEY = re.compile(r"^(?:sk_|pk_|rk_|AKIA|ghp_|gho_|xoxb-|xoxp-|AIza)[\w\-/+=]{8,}$")
    RANDOM_RUN = re.compile(r"^[A-Za-z0-9_\-+/=]{20,}$")

    def _is_credential(self, value):
        if self.LOOKS_LIKE_KEY.match(value):
            return True
        if not self.RANDOM_RUN.match(value) or "|" in value:
            return False
        # require entropy: letters, digits and a mix of cases (or base64 padding)
        has_digit, has_lower, has_upper = (
            any(c.isdigit() for c in value),
            any(c.islower() for c in value),
            any(c.isupper() for c in value),
        )
        return has_digit and (has_lower and has_upper or value.endswith("="))

    def test_no_hard_coded_secrets(self):
        hits = []
        for path in php_files("app", "config", "routes"):
            for number, line in enumerate(path.read_text(errors="ignore").splitlines(), 1):
                if "env(" in line:
                    continue
                for value in self.ASSIGNMENT.findall(line):
                    if self._is_credential(value):
                        hits.append(f"{path.relative_to(ROOT)}:{number}: {line.strip()[:140]}")
        self.assertEqual(hits, [], "\n".join(hits))

    def test_local_env_file_is_not_tracked(self):
        gitignore = (ROOT / ".gitignore").read_text()
        self.assertRegex(gitignore, r"(?m)^/?\.env\b")


class StaffQueryIsolationTest(unittest.TestCase):
    """`User` is the one model with no global scope (authentication resolves a user
    before a tenant exists), so every staff query must carry the hotel filter
    itself — the same rule system_audit.php applies as 7b.

    The exemption list is deliberately narrow: a stored attribute
    (`$this->recipient_users`) is already hotel-validated when it was saved, but the
    *request* array (`$d['recipient_users']`) is not, which is how a forged report
    schedule could have subscribed another property's staff.
    """

    SAFE = ("visibleToHotel", "hotel_id", "Tenant::id", "hotel()->id", "$this->recipient_users",
            "->recipient_users", "audit-ok", "->update(", "->delete(")
    QUERY = re.compile(r"(?<![A-Za-z])User::(where|find|findOrFail|pluck|whereIn|orderBy|all|get|query)\b")

    def test_every_staff_query_is_hotel_scoped(self):
        hits = []
        for path in php_files("app"):
            rel = str(path.relative_to(ROOT))
            if rel.startswith(("app/Console/", "app/Http/Controllers/Auth/")):
                continue  # no tenant yet: console options, login, 2FA challenge
            for number, line in enumerate(path.read_text(errors="ignore").splitlines(), 1):
                if any(marker in line for marker in self.SAFE):
                    continue
                if self.QUERY.search(line.replace("\\App\\Models\\", "")):
                    hits.append(f"{rel}:{number}: {line.strip()[:140]}")
        self.assertEqual(hits, [], "\n".join(hits))


class ConfigurationTest(unittest.TestCase):
    def test_php_files_have_no_stray_double_escaped_class_reference(self):
        hits = []
        for path in php_files("app", "database", "routes", "config"):
            for number, line in enumerate(path.read_text(errors="ignore").splitlines(), 1):
                if re.search(r"new\s+\\\\[A-Z]", line):
                    hits.append(f"{path.relative_to(ROOT)}:{number}: {line.strip()[:120]}")
        self.assertEqual(hits, [], "\n".join(hits))


if __name__ == "__main__":
    unittest.main(verbosity=2)
