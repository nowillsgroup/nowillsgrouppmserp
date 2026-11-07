#!/usr/bin/env python3
"""
Data check for the daily briefings service.

The sandbox has no PHP runtime and no database, so a briefing that names a column the
table does not have would only fail on the client's server. This check closes that gap: it
reads BriefingService.php the way Eloquent would and confirms, for every model it queries:

  1. the model class exists at app/Models/<Name>.php;
  2. the model's table exists in database/migrations/*.php;
  3. every column named in where / whereDate / whereNotNull / whereIn / orderBy / sum /
     avg / groupBy / pluck / get([...]) is a column that migration actually creates;
  4. every model referenced is real (a typo like \\App\\Models\\KeyLoanz fails here).

Columns created by a later Schema::table() are counted, so a column added by a follow-up
migration is not reported as missing.

Usage: python3 deploy/dev/audit/briefing_data_check.py
"""
import glob
import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
SERVICE = os.path.join(ROOT, 'app/Services/BriefingService.php')
MODELS = os.path.join(ROOT, 'app/Models')
MIGRATIONS = os.path.join(ROOT, 'database/migrations')

failures = []
checks = 0


def ok(condition, label):
    global checks
    checks += 1
    if not condition:
        failures.append(label)


def read(path):
    with open(path, encoding='utf-8') as handle:
        return handle.read()


# ── columns from the migrations ───────────────────────────────────────────────────────
def migration_columns():
    tables = {}
    for path in sorted(glob.glob(os.path.join(MIGRATIONS, '*.php'))):
        src = read(path)
        for match in re.finditer(r"Schema::(create|table)\('([a-z0-9_]+)'", src):
            start = src.find('{', match.end())
            if start < 0:
                continue
            depth, i = 0, start
            while i < len(src):
                if src[i] == '{':
                    depth += 1
                elif src[i] == '}':
                    depth -= 1
                    if depth == 0:
                        break
                i += 1
            block = src[start:i]
            table = match.group(2)
            tables.setdefault(table, set())
            for column in re.finditer(r"\$t(?:able)?->[a-zA-Z]+\('([a-zA-Z0-9_]+)'", block):
                tables[table].add(column.group(1))
            for column in re.finditer(r"->(?:after|renameColumn|dropColumn)\('([a-zA-Z0-9_]+)'", block):
                tables[table].add(column.group(1))
    return tables


TABLES = migration_columns()


def plural(snake):
    """Laravel's guess at a table name: y → ies, s → ses, else +s — plus the raw forms."""
    if snake.endswith('y') and not snake.endswith(('ay', 'ey', 'oy', 'uy')):
        return snake[:-1] + 'ies'
    if snake.endswith('s'):
        return snake + 'es'
    return snake + 's'


def table_for(model):
    """The table a model writes to: $table when declared, otherwise Laravel's plural."""
    path = os.path.join(MODELS, model + '.php')
    if not os.path.exists(path):
        return None, None
    src = read(path)
    declared = re.search(r"protected \$table = '([a-z0-9_]+)'", src)
    if declared:
        return declared.group(1), src
    snake = re.sub(r'(?<!^)(?=[A-Z])', '_', model).lower()
    for candidate in (plural(snake), snake + 's', snake, snake + 'es'):
        if candidate in TABLES:
            return candidate, src
    return plural(snake), src


# ── what the service asks of each model ──────────────────────────────────────────────
IMPLICIT = {'id', 'created_at', 'updated_at', 'deleted_at'}


def statement_from(src, start):
    """The whole Eloquent statement from $start to its top-level semicolon."""
    depth, quote, i = 0, None, start
    while i < len(src):
        ch = src[i]
        if quote:
            if ch == '\\':
                i += 2
                continue
            if ch == quote:
                quote = None
        elif ch in '"\'':
            quote = ch
        elif ch in '([{':
            depth += 1
        elif ch in ')]}':
            depth -= 1
        elif ch == ';' and depth <= 0:
            return src[start:i]
        i += 1
    return src[start:start + 900]


def strip_spans(text, calls):
    """Blank out the argument list of the given calls (a whereHas closure names another
    table's columns, and the audit must not attribute those to this model)."""
    for call in calls:
        for match in re.finditer(re.escape(call) + r'\s*\(', text):
            open_at = match.end() - 1
            depth, quote, i = 0, None, open_at
            while i < len(text):
                ch = text[i]
                if quote:
                    if ch == '\\':
                        i += 2
                        continue
                    if ch == quote:
                        quote = None
                elif ch in '"\'':
                    quote = ch
                elif ch == '(':
                    depth += 1
                elif ch == ')':
                    depth -= 1
                    if depth == 0:
                        break
                i += 1
            text = text[:open_at] + '()' + text[i + 1:]
    return text


service = read(SERVICE)
SERVICE_CLASSES = {'ActivityLogger', 'BudgetService', 'ManagementReportService', 'RecreationShiftService', 'StockTakeService', 'TransportService', 'TaxService'}
COLUMN_CALLS = r"(?:where|whereDate|whereMonth|whereNull|whereNotNull|whereIn|orderBy|orderByDesc|sum|avg|max|min|pluck|groupBy)\(\s*'([a-zA-Z0-9_]+)'"
LIST_CALLS = r"get\(\s*\[([^\]]*)\]"

models = {}
for match in re.finditer(r"(?<![\\\w])([A-Z][A-Za-z]+)::", service):
    name = match.group(1)
    if name in {'App', 'DB', 'Carbon', 'RuntimeException', 'Collection'} | SERVICE_CLASSES:
        continue
    if not os.path.exists(os.path.join(MODELS, name + '.php')):
        # Not a model and not one of the services this module is allowed to call: a typo.
        ok(False, f'model: {name} exists at app/Models/{name}.php')
        continue
    if re.match(r"[A-Z][A-Z0-9_]*\b(?!\s*\()", service[match.end():match.end() + 40]):
        continue  # Model::CONST — a constant, not a query
    tail = statement_from(service, match.end())
    tail = strip_spans(tail, ['whereHas', 'with', 'whereRelation', 'orWhereHas'])
    # A method call on a variable is a collection/date operation, not a column of this model.
    tail = re.sub(r"\$[A-Za-z_]\w*->\w+\([^()]*\)", '', tail)
    models.setdefault(name, set())
    for column in re.finditer(COLUMN_CALLS, tail):
        if column.group(1) not in IMPLICIT:
            models[name].add(column.group(1))
    for listed in re.finditer(LIST_CALLS, tail):
        for column in re.findall(r"'([a-zA-Z0-9_]+)'\s*(?:=>|,|$)", listed.group(1)):
            if column not in IMPLICIT:
                models[name].add(column)

ok(bool(models), 'service: the briefing data sources are readable')

for name, columns in sorted(models.items()):
    table, src = table_for(name)
    ok(table is not None, f'model: {name} exists at app/Models/{name}.php')
    if table is None:
        continue
    ok(table in TABLES, f'model: {name} writes to a table a migration creates ({table})')
    known = TABLES.get(table, set())
    if not known:
        continue
    for column in sorted(columns - IMPLICIT):
        ok(column in known, f'column: {name} reads {table}.{column}, which the migrations create')

# ── report ───────────────────────────────────────────────────────────────────────────
print(f'briefing data check: {checks - len(failures)}/{checks} passed')
if failures:
    for label in failures:
        print(f'  FAIL {label}')
    sys.exit(1)
print(f'  models checked: {len(models)} · tables known: {len(TABLES)}')
