#!/usr/bin/env python3
"""Static regression checks for the Banquet events and enquiries workspace."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
CONTROLLER = (ROOT / "app/Http/Controllers/BanquetController.php").read_text()
INDEX = (ROOT / "resources/views/banquet/index.blade.php").read_text()
FORM = (ROOT / "resources/views/banquet/form.blade.php").read_text()
CSS = (ROOT / "public/css/app.css").read_text()


class BanquetWorkspacePolishTest(unittest.TestCase):
    def test_search_covers_event_and_client_contact_details_safely(self):
        self.assertIn("is_string($search)", CONTROLLER)
        self.assertIn("mb_substr(trim($search), 0, 150)", CONTROLLER)
        for field in ("event_no", "title", "contact_name", "contact_phone", "contact_email"):
            with self.subTest(field=field):
                self.assertTrue(f"where('{field}'" in CONTROLLER or f"orWhere('{field}'" in CONTROLLER)
        self.assertIn("orWhereHas('company'", CONTROLLER)
        self.assertIn("FILTER_VALIDATE_INT", CONTROLLER)
        self.assertIn("array_key_exists($requestedTab, self::TABS)", CONTROLLER)

    def test_event_workspace_explains_pipeline_and_improves_findability(self):
        for expected in (
            'aria-label="Banquet event workflow"',
            'aria-label="Filter banquet events"',
            'aria-live="polite"',
            'type="search"',
            'Event number, title, client, phone or email',
            'mailto:{{ $e->contact_email }}',
            'Clear filters',
            'Create an enquiry',
            'Ready to bill',
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, INDEX)

    def test_enquiry_form_has_guided_sections_and_nonbinding_status_choices(self):
        self.assertIn('class="bq-form-steps"', FORM)
        self.assertIn('href="#bqEventDetails"', FORM)
        self.assertIn('href="#bqFunctionPlan"', FORM)
        self.assertIn('href="#bqEstimate"', FORM)
        self.assertIn('class="bq-form-note"', FORM)
        self.assertIn('An enquiry is non-binding.', FORM)
        self.assertIn('role="radiogroup" aria-labelledby="startStatusLabel" aria-describedby="statusHint"', FORM)
        self.assertIn('hint="Required for a tentative hold.', FORM)
        self.assertIn('opt.required = tentative;', FORM)
        self.assertIn('id="bqFunctionPlan"', FORM)
        self.assertIn('class="bq-fn-items-scroll"', FORM)
        self.assertIn('class="fn-items-count tiny muted"', FORM)
        self.assertIn('const MAX_FUNCTIONS = 30, MAX_ITEMS = 40, MAX_DIETS = 12;', FORM)
        self.assertIn('list.children.length >= MAX_FUNCTIONS', FORM)
        self.assertIn('tbody.children.length >= MAX_ITEMS', FORM)
        self.assertIn('class="card sticky-side bq-estimate"', FORM)
        self.assertIn('Save enquiry', FORM)

    def test_styles_cover_responsive_and_token_based_banquet_surfaces(self):
        for expected in (
            '.bq-intro',
            '.bq-stats { grid-template-columns: repeat(5',
            '.bq-filter-panel',
            '.bq-workflow-note',
            '@media (max-width: 760px)',
            '@media (max-width: 480px)',
            'var(--c-surface)',
            'var(--c-border)',
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, CSS)


if __name__ == "__main__":
    unittest.main(verbosity=2)
