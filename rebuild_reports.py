#!/usr/bin/env python3
"""Rebuild the consolidated REPORTS constant with all 469 entries."""
import re, json

# Read the current file (original 259 entries)
with open('app/Http/Controllers/ReportController.php', 'r') as f:
    content = f.read()

# Find REPORTS constant boundaries
rs_idx = content.index('public const REPORTS')
re_idx = content.index('];', rs_idx) + 2
reports_block = content[rs_idx:re_idx]

# Extract original entries
original = {}
for line in reports_block.split('\n'):
    m = re.match(r"\s*'(\w+)' => (.*)", line)
    if m:
        slug = m.group(1)
        rest = m.group(2).rstrip()
        original[slug] = rest

# Read preview HTML data
with open('public/preview-reports.html', 'r') as f:
    html = f.read()

# Extract preview entries: slug -> (title, description)
preview = {}
current_group = None
start = html.find('const REPORTS = {')
end = html.find('};\n', start) + 2
block = html[start:end]
for line in block.split('\n'):
    gm = re.match(r'\s*"([^"]+)":\s*\{', line)
    if gm:
        current_group = gm.group(1)
        continue
    rm = re.match(r'\s*(\w+):\s*\["([^"]+)",\s*"([^"]+)"\]', line)
    if rm and current_group:
        preview[rm.group(1)] = (rm.group(2), rm.group(3))

# All 210 new slugs from batches 1-4
NEW_SLUGS = [
    # Batch 1 (39)
    'cashier_shifts', 'reservation_amendments', 'room_blocks', 'room_counts', 'room_moves',
    'room_outages', 'stock_takes', 'stock_take_lines', 'store_pars', 'time_punches_detail',
    'attendance', 'pay_components', 'tip_collections', 'staff_advances', 'bank_matches',
    'gateway_payouts', 'online_refunds', 'supplier_prices', 'grn_lines', 'sales_contacts',
    'sales_opportunities', 'channel_rate_maps', 'channel_room_maps', 'loyalty_entries',
    'security_watch', 'menu_items', 'shift_templates', 'utility_bills', 'pm_schedules',
    'upsell_offers', 'rfq_register', 'rental_vehicles', 'car_rentals', 'parking_vehicles',
    'training_sessions', 'credit_logs', 'activity_bookings', 'reservation_waitlist', 'minibar_items',
    # Batch 2 (38)
    'inventory_items', 'item_categories', 'store_issue_lines', 'po_items', 'supplier_invoice_lines',
    'requisition_lines', 'rfq_quotes', 'rfq_quote_lines', 'rfq_suppliers', 'banquet_packages',
    'banquet_services', 'banquet_event_items', 'menu_categories', 'menu_modifier_groups',
    'menu_modifiers', 'menu_item_recipes', 'pos_tables', 'room_amenities', 'spa_rooms',
    'stay_fixed_charges', 'leave_types', 'employee_pay_components', 'roster_weeks', 'shift_swaps',
    'budget_lines', 'budget_events', 'business_sources', 'payment_modes', 'ap_allocations',
    'agent_commissions', 'recurring_journals', 'training_courses', 'sop_documents', 'sop_versions',
    'kiosks', 'kot_print_jobs', 'fb_recipe_cards', 'fb_cost_snapshots',
    # Batch 3 (66)
    'linen_batches', 'linen_batch_lines', 'laundry_items', 'laundry_order_lines', 'asset_categories',
    'asset_dep_runs', 'gl_journals', 'cash_forecast_lines', 'budget_stats', 'fiscal_year_closes',
    'club_classes', 'club_ticket_types', 'club_ticket_sales', 'club_ticket_sale_items',
    'club_ticket_payments', 'channel_restrictions', 'channel_ari_states', 'channel_messages',
    'ota_channels', 'rate_plans', 'rate_overrides', 'rate_contract_rates', 'rate_contract_blackouts',
    'reservation_desk_views', 'security_incident_updates', 'petty_counts', 'petty_replenishments',
    'surveys', 'survey_invitations', 'survey_questions', 'talent_cycles', 'talent_interviews',
    'talent_offers', 'talent_probations', 'talent_scorecards', 'talent_tasks', 'compliance_events',
    'fs_certificates', 'fs_equipment', 'fs_labels', 'esg_policies', 'esg_targets', 'esg_communities',
    'esg_green_stays', 'esg_laundry_months', 'esg_checklist_items', 'tv_movies', 'tv_pages',
    'tv_programmes', 'tv_sessions', 'tv_commands', 'tv_welcome_templates', 'concierge_places',
    'cro_contacts', 'dm_ad_stats', 'dm_link_clicks', 'einvoice_logs', 'gst_periods',
    'gst_cancelled_docs', 'wht_periods', 'kpi_alerts', 'report_schedules', 'report_views',
    'interface_commands', 'message_logs', 'email_logs',
    # Batch 4 (67 new unique — sales_targets excluded as duplicate)
    'cashier_movements', 'forex_rates', 'forex_surrenders', 'gate_pass_items', 'gate_pass_returns',
    'guest_documents', 'hotel_groups', 'management_agreements', 'owner_statements', 'owner_queries',
    'yield_rules', 'bd_competitors', 'bd_milestones', 'bd_rate_shop_lines', 'bd_results',
    'qa_standards', 'qa_audit_items', 'review_templates', 'marketing_segments', 'marketing_messages',
    'marketing_collateral', 'lease_inspections', 'lease_meter_readings', 'meter_readings',
    'sop_acknowledgements', 'training_requirements', 'transport_tariffs', 'outlet_service_periods',
    'fb_staff_meals', 'fb_yield_tests', 'hr_announcements', 'hr_documents', 'hr_profile_updates',
    'hse_wardens', 'kot_printers', 'rfq_lines', 'sc_distribution_lines', 'organiser_attendees',
    'feedback_answers', 'feedback_case_events', 'activity_departures', 'rm_snapshots',
    'room_amenity_links', 'room_block_lines', 'room_images', 'billing_reconciliations',
    'billing_reconciliation_items', 'expense_claim_lines', 'inbox_broadcasts', 'inbox_quick_replies',
    'offline_sync_operations', 'online_payment_events', 'privacy_request_events', 'data_breaches',
    'data_migration_batches', 'data_migration_rows', 'historical_stock_movement_archives',
    'marketing_consent_logs', 'supplier_paid_history_archives', 'close_events',
    'api_idempotency_keys', 'permissions', 'settings', 'tv_message_reads', 'wakeup_attempts',
    'log_entry_comments', 'log_entry_reads',
]

# Consolidated group mapping for new slugs
GROUP_MAP = {
    # Front Office
    'cashier_shifts': 'Front Office', 'cashier_movements': 'Front Office',
    'reservation_amendments': 'Front Office', 'room_blocks': 'Front Office',
    'room_block_lines': 'Front Office', 'room_counts': 'Front Office',
    'room_moves': 'Front Office', 'room_outages': 'Front Office',
    'room_amenities': 'Front Office', 'room_amenity_links': 'Front Office',
    'room_images': 'Front Office', 'stay_fixed_charges': 'Front Office',
    'guest_documents': 'Front Office', 'reservation_desk_views': 'Front Office',
    'reservation_waitlist': 'Front Office', 'wakeup_attempts': 'Front Office',
    'loyalty_entries': 'Front Office', 'activity_bookings': 'Front Office',
    'activity_departures': 'Front Office', 'upsell_offers': 'Front Office',
    # Housekeeping
    'room_status': 'Housekeeping', 'hk_productivity': 'Housekeeping',
    'minibar': 'Housekeeping', 'minibar_items': 'Housekeeping',
    'maintenance': 'Housekeeping',
    # Inventory & Purchasing
    'inventory_items': 'Inventory & Purchasing', 'item_categories': 'Inventory & Purchasing',
    'store_pars': 'Inventory & Purchasing', 'store_issue_lines': 'Inventory & Purchasing',
    'stock_takes': 'Inventory & Purchasing', 'stock_take_lines': 'Inventory & Purchasing',
    'supplier_prices': 'Inventory & Purchasing', 'po_items': 'Inventory & Purchasing',
    'open_purchase_orders': 'Inventory & Purchasing', 'goods_received': 'Inventory & Purchasing',
    'grn_lines': 'Inventory & Purchasing', 'supplier_invoice_lines': 'Inventory & Purchasing',
    'requisition_lines': 'Inventory & Purchasing', 'req_register': 'Inventory & Purchasing',
    'req_pending': 'Inventory & Purchasing', 'rfq_register': 'Inventory & Purchasing',
    'rfq_quotes': 'Inventory & Purchasing', 'rfq_quote_lines': 'Inventory & Purchasing',
    'rfq_suppliers': 'Inventory & Purchasing', 'rfq_lines': 'Inventory & Purchasing',
    'purchases': 'Inventory & Purchasing', 'supplier_scorecard': 'Inventory & Purchasing',
    'historical_stock_movement_archives': 'Inventory & Purchasing',
    # Laundry & Linen
    'linen_batches': 'Laundry & Linen', 'linen_batch_lines': 'Laundry & Linen',
    'laundry_items': 'Laundry & Linen', 'laundry_order_lines': 'Laundry & Linen',
    'linen_losses': 'Laundry & Linen', 'linen_par': 'Laundry & Linen',
    'laundry_register': 'Laundry & Linen',
    # F&B & POS
    'menu_items': 'F&B & POS', 'menu_categories': 'F&B & POS',
    'menu_modifier_groups': 'F&B & POS', 'menu_modifiers': 'F&B & POS',
    'menu_item_recipes': 'F&B & POS', 'pos_tables': 'F&B & POS',
    'pos_bills': 'F&B & POS', 'pos_comps': 'F&B & POS', 'pos_items': 'F&B & POS',
    'pos_payments': 'F&B & POS', 'pos_sales': 'F&B & POS', 'pos_evat_register': 'F&B & POS',
    'table_bookings': 'F&B & POS', 'table_covers': 'F&B & POS',
    'table_turns': 'F&B & POS', 'table_waitlist': 'F&B & POS',
    'kot_print_jobs': 'F&B & POS', 'kot_printers': 'F&B & POS',
    'outlet_service_periods': 'F&B & POS', 'fb_staff_meals': 'F&B & POS',
    'fb_yield_tests': 'F&B & POS', 'fb_recipe_cards': 'F&B & POS',
    'fb_cost_snapshots': 'F&B & POS', 'banquet_packages': 'F&B & POS',
    'banquet_services': 'F&B & POS', 'banquet_event_items': 'F&B & POS',
    'kiosks': 'F&B & POS', 'spa_rooms': 'F&B & POS',
    'package_allocation': 'F&B & POS', 'package_consumption': 'F&B & POS',
    'package_forecast': 'F&B & POS',
    # Banquet & Events
    'banquet': 'Banquet & Events', 'banquet_pipeline': 'Banquet & Events',
    'function_forecast': 'Banquet & Events', 'venue_utilisation': 'Banquet & Events',
    'organiser_attendees': 'Banquet & Events',
    # Accounts
    'ap_allocations': 'Accounts', 'ap_ageing': 'Accounts', 'ap_register': 'Accounts',
    'expense_claims': 'Accounts', 'expenses': 'Accounts', 'expense_claim_lines': 'Accounts',
    'petty_cash_book': 'Accounts', 'petty_expenses': 'Accounts', 'petty_counts': 'Accounts',
    'petty_replenishments': 'Accounts', 'supplier_paid_history_archives': 'Accounts',
    'billing_reconciliations': 'Accounts', 'billing_reconciliation_items': 'Accounts',
    'close_events': 'Accounts', 'credit_exposure': 'Accounts',
    'contract_production': 'Accounts', 'allotment_utilisation': 'Accounts',
    # General Ledger
    'gl_journals': 'General Ledger', 'journal_register': 'General Ledger',
    'gl_pnl': 'General Ledger', 'balance_sheet': 'General Ledger',
    'trial_balance': 'General Ledger', 'accrual_register': 'General Ledger',
    'close_status': 'General Ledger', 'period_end_postings': 'General Ledger',
    'fiscal_year_closes': 'General Ledger', 'recurring_journals': 'General Ledger',
    'ia_authorisations': 'General Ledger', 'ia_findings': 'General Ledger',
    'ia_rate_variance': 'General Ledger', 'ia_reconciliation': 'General Ledger',
    # Treasury & Payments
    'cash_flow': 'Treasury & Payments', 'cash_position': 'Treasury & Payments',
    'cash_forecast_lines': 'Treasury & Payments', 'cheque_register': 'Treasury & Payments',
    'transfer_register': 'Treasury & Payments', 'gateway_payouts': 'Treasury & Payments',
    'online_refunds': 'Treasury & Payments', 'payment_modes': 'Treasury & Payments',
    'online_payments': 'Treasury & Payments', 'online_payment_events': 'Treasury & Payments',
    'gift_voucher_register': 'Treasury & Payments', 'voucher_breakage': 'Treasury & Payments',
    'voucher_liability': 'Treasury & Payments', 'voucher_redemptions': 'Treasury & Payments',
    'voucher_sales': 'Treasury & Payments',
    # Payroll
    'attendance': 'Payroll', 'time_punches_detail': 'Payroll', 'pay_components': 'Payroll',
    'employee_pay_components': 'Payroll', 'tip_collections': 'Payroll',
    'staff_advances': 'Payroll', 'leave_types': 'Payroll', 'leave_register': 'Payroll',
    'roster_weeks': 'Payroll', 'shift_swaps': 'Payroll', 'shift_templates': 'Payroll',
    'timesheet': 'Payroll', 'payroll_register': 'Payroll', 'payroll_statutory': 'Payroll',
    'paye_schedule': 'Payroll', 'ssnit_schedule': 'Payroll',
    'statutory_remittances': 'Payroll', 'roster_vs_actual': 'Payroll',
    'service_charge_distribution': 'Payroll', 'sc_distribution_lines': 'Payroll',
    # HR & People
    'hr_announcements': 'HR & People', 'hr_documents': 'HR & People',
    'hr_profile_updates': 'HR & People',
    # Talent Management
    'talent_cycles': 'Talent Management', 'talent_interviews': 'Talent Management',
    'talent_offers': 'Talent Management', 'talent_probations': 'Talent Management',
    'talent_scorecards': 'Talent Management', 'talent_tasks': 'Talent Management',
    'talent_appraisals': 'Talent Management', 'talent_discipline': 'Talent Management',
    'talent_exits': 'Talent Management', 'talent_headcount': 'Talent Management',
    'talent_pipeline': 'Talent Management', 'talent_vacancies': 'Talent Management',
    # Marketing & CRM
    'marketing_segments': 'Marketing & CRM', 'marketing_messages': 'Marketing & CRM',
    'marketing_collateral': 'Marketing & CRM', 'marketing_consent_logs': 'Marketing & CRM',
    'marketing_budget': 'Marketing & CRM', 'marketing_roi': 'Marketing & CRM',
    'crm_campaigns': 'Marketing & CRM', 'crm_guest_value': 'Marketing & CRM',
    'crm_promos': 'Marketing & CRM',
    'dm_ad_stats': 'Marketing & CRM', 'dm_link_clicks': 'Marketing & CRM',
    'digital_content': 'Marketing & CRM', 'digital_influencers': 'Marketing & CRM',
    'digital_links': 'Marketing & CRM', 'digital_paid': 'Marketing & CRM',
    'digital_social': 'Marketing & CRM',
    'concierge_places': 'Marketing & CRM', 'cro_contacts': 'Marketing & CRM',
    # Sales & Business Development
    'sales_targets': 'Sales & Business Development', 'sales_contacts': 'Sales & Business Development',
    'sales_opportunities': 'Sales & Business Development', 'sales_activity': 'Sales & Business Development',
    'sales_pipeline': 'Sales & Business Development', 'sales_win_loss': 'Sales & Business Development',
    'agent_commissions': 'Sales & Business Development', 'account_production': 'Sales & Business Development',
    'bd_competitors': 'Sales & Business Development', 'bd_milestones': 'Sales & Business Development',
    'bd_rate_shop_lines': 'Sales & Business Development', 'bd_results': 'Sales & Business Development',
    'bd_benchmark': 'Sales & Business Development', 'bd_initiatives': 'Sales & Business Development',
    'bd_market_mix': 'Sales & Business Development', 'bd_partners': 'Sales & Business Development',
    'bd_rate_shop': 'Sales & Business Development',
    # Revenue Management
    'yield_rules': 'Revenue Management', 'rm_snapshots': 'Revenue Management',
    'rate_plans': 'Revenue Management', 'rate_overrides': 'Revenue Management',
    'rate_contract_rates': 'Revenue Management', 'rate_contract_blackouts': 'Revenue Management',
    'rm_forecast': 'Revenue Management', 'rm_pickup': 'Revenue Management',
    # Channel Manager
    'channel_restrictions': 'Channel Manager', 'channel_ari_states': 'Channel Manager',
    'channel_messages': 'Channel Manager', 'ota_channels': 'Channel Manager',
    'channel_rate_maps': 'Channel Manager', 'channel_room_maps': 'Channel Manager',
    'channel_parity': 'Channel Manager', 'channel_production': 'Channel Manager',
    'channel_sync_log': 'Channel Manager',
    # Central Reservations
    'cro_agents': 'Central Reservations', 'cro_demand': 'Central Reservations',
    'cro_enquiries': 'Central Reservations', 'cro_lost_business': 'Central Reservations',
    'cro_referrals': 'Central Reservations',
    # Quality Assurance
    'qa_standards': 'Quality Assurance', 'qa_audit_items': 'Quality Assurance',
    'review_templates': 'Quality Assurance', 'qa_actions': 'Quality Assurance',
    'qa_audits': 'Quality Assurance', 'qa_department_scores': 'Quality Assurance',
    'qa_standard_failures': 'Quality Assurance', 'qa_template_compliance': 'Quality Assurance',
    # Compliance & HSE
    'compliance_events': 'Compliance & HSE', 'compliance_register': 'Compliance & HSE',
    'data_breaches': 'Compliance & HSE', 'privacy_request_events': 'Compliance & HSE',
    'pii_access': 'Compliance & HSE', 'privacy_requests': 'Compliance & HSE',
    'privacy_retention': 'Compliance & HSE',
    'pm_compliance': 'Compliance & HSE',
    'hse_wardens': 'Compliance & HSE', 'hse_accidents': 'Compliance & HSE',
    'hse_checks': 'Compliance & HSE', 'hse_drills': 'Compliance & HSE',
    'hse_equipment': 'Compliance & HSE', 'hse_permits': 'Compliance & HSE',
    'hse_risks': 'Compliance & HSE',
    # Food Safety
    'fs_certificates': 'Food Safety', 'fs_equipment': 'Food Safety',
    'fs_labels': 'Food Safety', 'food_waste': 'Food Safety',
    'haccp_checklists': 'Food Safety', 'haccp_temperatures': 'Food Safety',
    # Property & Utilities
    'lease_inspections': 'Property & Utilities', 'lease_meter_readings': 'Property & Utilities',
    'meter_readings': 'Property & Utilities', 'lease_arrears': 'Property & Utilities',
    'lease_expiry': 'Property & Utilities', 'lease_rent_roll': 'Property & Utilities',
    'energy_kpis': 'Property & Utilities', 'utility_bills': 'Property & Utilities',
    'utility_consumption': 'Property & Utilities', 'utility_bill_reconciliation': 'Property & Utilities',
    # Sustainability & ESG
    'esg_policies': 'Sustainability & ESG', 'esg_targets': 'Sustainability & ESG',
    'esg_communities': 'Sustainability & ESG', 'esg_green_stays': 'Sustainability & ESG',
    'esg_laundry_months': 'Sustainability & ESG', 'esg_checklist_items': 'Sustainability & ESG',
    'esg_carbon': 'Sustainability & ESG', 'esg_green': 'Sustainability & ESG',
    'esg_initiatives': 'Sustainability & ESG', 'esg_local_spend': 'Sustainability & ESG',
    'esg_refrigerants': 'Sustainability & ESG', 'esg_waste': 'Sustainability & ESG',
    'carbon_footprint': 'Sustainability & ESG',
    # Fixed Assets & Engineering
    'asset_categories': 'Fixed Assets & Engineering', 'asset_dep_runs': 'Fixed Assets & Engineering',
    'asset_register': 'Fixed Assets & Engineering', 'asset_schedule': 'Fixed Assets & Engineering',
    'pm_schedules': 'Fixed Assets & Engineering',
    # Hotel Group & Owner Reporting
    'hotel_groups': 'Hotel Group & Owner Reporting', 'management_agreements': 'Hotel Group & Owner Reporting',
    'owner_statements': 'Hotel Group & Owner Reporting', 'owner_queries': 'Hotel Group & Owner Reporting',
    'management_fees': 'Hotel Group & Owner Reporting', 'ffe_reserve': 'Hotel Group & Owner Reporting',
    'hotel_kpis': 'Hotel Group & Owner Reporting', 'usali_departments': 'Hotel Group & Owner Reporting',
    'usali_summary': 'Hotel Group & Owner Reporting',
    # Security
    'gate_pass_items': 'Security', 'gate_pass_returns': 'Security',
    'security_gatepasses': 'Security', 'security_incidents': 'Security',
    'security_incident_updates': 'Security', 'security_outstanding': 'Security',
    'security_visitors': 'Security', 'security_watch': 'Security',
    # Health Club & Spa
    'club_classes': 'Health Club & Spa', 'club_ticket_types': 'Health Club & Spa',
    'club_ticket_sales': 'Health Club & Spa', 'club_ticket_sale_items': 'Health Club & Spa',
    'club_ticket_payments': 'Health Club & Spa', 'club_attendance': 'Health Club & Spa',
    'club_revenue': 'Health Club & Spa', 'club_members': 'Health Club & Spa',
    'spa_revenue': 'Health Club & Spa',
    # Concierge & Activities
    'activity_departures': 'Concierge & Activities', 'concierge_bookings': 'Concierge & Activities',
    'concierge_sales': 'Concierge & Activities', 'concierge_staff': 'Concierge & Activities',
    'concierge_vendors': 'Concierge & Activities',
    # IPTV & Guest Technology
    'tv_movies': 'IPTV & Guest Technology', 'tv_pages': 'IPTV & Guest Technology',
    'tv_programmes': 'IPTV & Guest Technology', 'tv_sessions': 'IPTV & Guest Technology',
    'tv_commands': 'IPTV & Guest Technology', 'tv_welcome_templates': 'IPTV & Guest Technology',
    'tv_message_reads': 'IPTV & Guest Technology',
    'iptv_channels': 'IPTV & Guest Technology', 'iptv_devices': 'IPTV & Guest Technology',
    'iptv_engagement': 'IPTV & Guest Technology', 'iptv_messages': 'IPTV & Guest Technology',
    'iptv_orders': 'IPTV & Guest Technology', 'iptv_vod': 'IPTV & Guest Technology',
    # Transport & Parking
    'transport_tariffs': 'Transport & Parking', 'car_rentals': 'Transport & Parking',
    'parking_vehicles': 'Transport & Parking', 'rental_vehicles': 'Transport & Parking',
    'transport_drivers': 'Transport & Parking', 'transport_revenue': 'Transport & Parking',
    'transport_trips': 'Transport & Parking', 'transport_vehicles': 'Transport & Parking',
    'parking_occupancy': 'Transport & Parking', 'parking_permits': 'Transport & Parking',
    'parking_revenue': 'Transport & Parking', 'parking_sessions': 'Transport & Parking',
    'parking_valet': 'Transport & Parking',
    # Budget & Planning
    'budget_lines': 'Budget & Planning', 'budget_events': 'Budget & Planning',
    'budget_stats': 'Budget & Planning', 'budget_annual': 'Budget & Planning',
    'budget_kpis': 'Budget & Planning', 'budget_vs_actual': 'Budget & Planning',
    'forecast_vs_budget': 'Budget & Planning', 'variance_commentary': 'Budget & Planning',
    # Management & MIS
    'kpi_alerts': 'Management & MIS', 'kpi_alert_log': 'Management & MIS',
    'report_schedules': 'Management & MIS', 'report_views': 'Management & MIS',
    'message_logs': 'Management & MIS', 'email_logs': 'Management & MIS',
    'rpt_deliveries': 'Management & MIS', 'user_activity': 'Management & MIS',
    'flash': 'Management & MIS', 'mis': 'Management & MIS',
    'guest_history': 'Management & MIS',
    # System & Administration
    'permissions': 'System & Administration', 'settings': 'System & Administration',
    'offline_sync_operations': 'System & Administration',
    'api_idempotency_keys': 'System & Administration',
    'data_migration_batches': 'System & Administration', 'data_migration_rows': 'System & Administration',
    'log_entry_comments': 'System & Administration', 'log_entry_reads': 'System & Administration',
    # Learning & Development
    'sop_acknowledgements': 'Learning & Development', 'sop_acknowledgement': 'Learning & Development',
    'sop_documents': 'Learning & Development', 'sop_versions': 'Learning & Development',
    'training_requirements': 'Learning & Development', 'training_courses': 'Learning & Development',
    'training_sessions': 'Learning & Development', 'training_records': 'Learning & Development',
    'training_compliance': 'Learning & Development', 'training_expiry': 'Learning & Development',
    'training_hours': 'Learning & Development',
    # Feedback & Reputation
    'feedback_answers': 'Feedback & Reputation', 'feedback_case_events': 'Feedback & Reputation',
    'feedback_cases': 'Feedback & Reputation', 'feedback_register': 'Feedback & Reputation',
    'surveys': 'Feedback & Reputation', 'survey_questions': 'Feedback & Reputation',
    'survey_invitations': 'Feedback & Reputation',
    'online_reviews': 'Feedback & Reputation', 'nps_trend': 'Feedback & Reputation',
    'dept_scores': 'Feedback & Reputation',
    # Tax & Statutory
    'einvoice_logs': 'Tax & Statutory', 'gst_periods': 'Tax & Statutory',
    'gst_cancelled_docs': 'Tax & Statutory', 'wht_periods': 'Tax & Statutory',
    'credit_notes': 'Tax & Statutory', 'einvoice_register': 'Tax & Statutory',
    'hsn_summary': 'Tax & Statutory', 'wht_register': 'Tax & Statutory',
    'tax': 'Tax & Statutory',
    # Foreign Guests & Forex
    'forex_rates': 'Foreign Guests & Forex', 'forex_surrenders': 'Foreign Guests & Forex',
    'foreign_arrivals': 'Foreign Guests & Forex', 'forex_position': 'Foreign Guests & Forex',
    'forex_register': 'Foreign Guests & Forex', 'formc_register': 'Foreign Guests & Forex',
    # Hotel Interfaces
    'interface_commands': 'Hotel Interfaces', 'admin_call_usage': 'Hotel Interfaces',
    'call_log': 'Hotel Interfaces', 'call_revenue': 'Hotel Interfaces',
    'key_card_audit': 'Hotel Interfaces', 'master_key_register': 'Hotel Interfaces',
    'api_usage': 'Hotel Interfaces', 'webhook_deliveries': 'Hotel Interfaces',
    # Guest Messaging
    'inbox_broadcasts': 'Guest Messaging', 'inbox_quick_replies': 'Guest Messaging',
    'inbox_agents': 'Guest Messaging', 'inbox_conversations': 'Guest Messaging',
    'inbox_response': 'Guest Messaging', 'inbox_topics': 'Guest Messaging',
    'guest_messages': 'Guest Messaging',
    # Partner & Organiser Portals
    'organiser_attendees': 'Partner & Organiser Portals',
    'organiser_access': 'Partner & Organiser Portals', 'organiser_events': 'Partner & Organiser Portals',
    'organiser_payments': 'Partner & Organiser Portals', 'organiser_requests': 'Partner & Organiser Portals',
    'partner_access': 'Partner & Organiser Portals', 'partner_bookings': 'Partner & Organiser Portals',
    'partner_production': 'Partner & Organiser Portals', 'partner_requests': 'Partner & Organiser Portals',
    # Self-Service Kiosk
    'kiosks': 'Self-Service Kiosk', 'kiosk_adoption': 'Self-Service Kiosk',
    'kiosk_assist': 'Self-Service Kiosk', 'kiosk_payments': 'Self-Service Kiosk',
    'kiosk_sessions': 'Self-Service Kiosk',
    # Billing
    'cashier_shifts': 'Billing', 'cashier_movements': 'Billing',
    'collection': 'Billing', 'credit_logs': 'Billing', 'invoices': 'Billing',
    'online_payments': 'Billing', 'outstanding': 'Billing', 'revenue': 'Billing',
    'tax': 'Billing', 'voids': 'Billing',
};

# Specific moves for reports that need different groups
SPECIFIC_MOVES = {
    'cashier_shifts': 'Billing', 'cashier_movements': 'Billing',
    'activity_departures': 'Concierge & Activities',
    'organiser_attendees': 'Banquet & Events',
    'kiosks': 'Self-Service Kiosk',
    'spa_rooms': 'F&B & POS',
    'menu_items': 'F&B & POS',
    'training_sessions': 'Learning & Development',
    'room_amenities': 'Front Office',
    'pm_schedules': 'Fixed Assets & Engineering',
    'pm_compliance': 'Compliance & HSE',
    'energy_kpis': 'Property & Utilities',
    'utility_bills': 'Property & Utilities',
    'utility_consumption': 'Property & Utilities',
    'utility_bill_reconciliation': 'Property & Utilities',
    'carbon_footprint': 'Sustainability & ESG',
    'compliance_events': 'Compliance & HSE',
    'compliance_register': 'Compliance & HSE',
    'sop_acknowledgements': 'Learning & Development',
    'training_requirements': 'Learning & Development',
}

def get_group(slug):
    if slug in SPECIFIC_MOVES:
        return SPECIFIC_MOVES[slug]
    if slug in GROUP_MAP:
        return GROUP_MAP[slug]
    return 'Other'

def get_filter_mode(slug, group):
    """Determine filter mode based on report type."""
    # Registers and static data: 'none'
    static_prefixes = ('asset_cat', 'bd_comp', 'bd_rate', 'bd_res', 'bd_mil', 'channel_', 'club_',
        'einvoice', 'esg_', 'fs_', 'gst_', 'hr_', 'hse_', 'kpi_', 'mkt_', 'ota_', 'qa_', 'rate_',
        'report_', 'settings', 'sop_', 'talent_', 'training_', 'tv_', 'wht_', 'yield_', 'data_mig',
        'inventory', 'item_cat', 'menu_', 'pos_table', 'room_amen', 'room_image', 'room_block_line',
        'shift_', 'leave_', 'roster_', 'business_source', 'payment_mode', 'agent_comm', 'recurring_',
        'organiser_', 'partner_', 'kiosk', 'budget_', 'package_', 'transport_', 'meter_', 'lease_',
        'owner_', 'hotel_', 'management_', 'bd_', 'marketing_', 'digital_', 'concierge_', 'cro_',
        'compliance_', 'privacy_', 'data_breach', 'log_entry', 'offline_sync', 'api_id', 'permission',
        'outlet_', 'fb_', 'banquet_', 'laundry_', 'linen_', 'petty_', 'survey_', 'feedback_',
        'hotel_', 'review_', 'auth_')
    for p in static_prefixes:
        if slug.startswith(p):
            return 'none'
    return 'range'

def get_guard(group):
    """Map group to permission guard."""
    guard_map = {
        'Front Office': 'frontoffice.view',
        'Billing': 'frontoffice.view',
        'Housekeeping': 'housekeeping.view',
        'Inventory & Purchasing': 'inventory.view',
        'Laundry & Linen': 'inventory.view',
        'F&B & POS': 'fnb.view',
        'Banquet & Events': 'banquet.view',
        'Accounts': 'accounts.view',
        'General Ledger': 'accounts.view',
        'Treasury & Payments': 'accounts.view',
        'Payroll': 'payroll.view',
        'HR & People': 'hr.view',
        'Talent Management': 'hr.view',
        'Marketing & CRM': 'marketing.view',
        'Sales & Business Development': 'sales.view',
        'Revenue Management': 'revenue.view',
        'Channel Manager': 'channel.view',
        'Central Reservations': 'cro.view',
        'Quality Assurance': 'qa.view',
        'Compliance & HSE': 'compliance.view',
        'Food Safety': 'foodsafety.view',
        'Property & Utilities': 'property.view',
        'Sustainability & ESG': 'esg.view',
        'Fixed Assets & Engineering': 'assets.view',
        'Hotel Group & Owner Reporting': 'hotels.view',
        'Security': 'security.view',
        'Health Club & Spa': 'club.view',
        'Concierge & Activities': 'activity.view',
        'IPTV & Guest Technology': 'iptv.view',
        'Transport & Parking': 'transport.view',
        'Budget & Planning': 'budget.view',
        'Management & MIS': None,
        'System & Administration': 'system.view',
        'Learning & Development': 'training.view',
        'Feedback & Reputation': 'feedback.view',
        'Tax & Statutory': 'tax.view',
        'Foreign Guests & Forex': 'forex.view',
        'Hotel Interfaces': 'interfaces.view',
        'Guest Messaging': 'inbox.view',
        'Partner & Organiser Portals': 'partners.view',
        'Self-Service Kiosk': 'kiosk.view',
    }
    return guard_map.get(group)

# Build new entries
all_entries = {}

# Original 259 entries — update group names
for slug, rest in original.items():
    m = re.match(r"\['([^']+)'", rest)
    old_group = m.group(1) if m else 'Other'
    new_group = get_group(slug) if slug in GROUP_MAP or slug in SPECIFIC_MOVES else old_group
    # If the group is already the consolidated name, keep it
    if new_group == old_group or new_group == 'Other':
        all_entries[slug] = rest
    else:
        new_rest = re.sub(r"\['([^']+)'", f"['{new_group}'", rest, count=1)
        all_entries[slug] = new_rest

# New 210 entries
for slug in NEW_SLUGS:
    if slug in all_entries:
        continue  # skip if already exists (e.g., sales_targets)
    group = get_group(slug)
    # Get title and description from preview HTML
    title, desc = preview.get(slug, ('', ''))
    if not title:
        # Generate title from slug
        words = slug.replace('_', ' ').title()
        title = f"{words} Register"
    if not desc:
        desc = f"Detailed register of {words} records"
    filter_mode = get_filter_mode(slug, group)
    guard = get_guard(group)
    if guard:
        entry = f"['{group}', '{title}', '{desc}', '{filter_mode}', '{guard}']"
    else:
        entry = f"['{group}', '{title}', '{desc}', '{filter_mode}']"
    all_entries[slug] = entry

# Sort by group, then alphabetically by slug
from collections import defaultdict
groups = defaultdict(list)
for slug, rest in all_entries.items():
    m = re.match(r"\['([^']+)'", rest)
    group = m.group(1) if m else 'Other'
    groups[group].append((slug, rest))

# Build new REPORTS block
new_lines = []
new_lines.append("    public const REPORTS = [")

for group in sorted(groups.keys()):
    for slug, rest in sorted(groups[group], key=lambda x: x[0]):
        new_lines.append(f"        '{slug}' => {rest}")

new_lines.append("    ];")

new_block = '\n'.join(new_lines)

# Replace in content
content = content.replace(reports_block, new_block)

# Write back
with open('app/Http/Controllers/ReportController.php', 'w') as f:
    f.write(content)

# Print summary
print(f"Rebuilt REPORTS constant: {len(all_entries)} entries in {len(groups)} groups")
for g in sorted(groups.keys()):
    print(f"  [{g}] ({len(groups[g])} reports)")
