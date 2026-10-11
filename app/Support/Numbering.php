<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Concurrency-safe, per-hotel document numbering (RES-00001, INV-00001 ...).
 */
class Numbering
{
    public const PREFIXES = [
        'reservation' => 'RES', 'folio' => 'FOL', 'invoice' => 'INV', 'pos_order' => 'ORD',
        'pos_bill' => 'BIL', 'kot' => '', 'banquet' => 'BQT', 'block' => 'BLK', 'po' => 'PO', 'voucher' => 'VCH',
        'ticket' => 'MNT', 'employee' => 'EMP', 'stock_take' => 'STK', 'spa' => 'SPA', 'journal' => 'JV', 'guest_request' => 'GRQ', 'conversation' => 'CNV', 'inbox_broadcast' => 'BRD', 'supplier_bill' => 'BILL', 'debit_note' => 'DN', 'supplier_payment' => 'SPY', 'staff_advance' => 'ADV', 'online_payment' => 'PL', 'gateway_payout' => 'GPO', 'laundry_order' => 'LO', 'linen_batch' => 'LB', 'asset' => 'FA', 'requisition' => 'REQ', 'store_issue' => 'SIN', 'privacy_request' => 'PRQ', 'credit_note' => 'CN', 'feedback_case' => 'CASE', 'table_booking' => 'TBL', 'transport_trip' => 'TRP', 'form_c' => 'FC', 'forex' => 'FX', 'forex_surrender' => 'FXS', 'guest_message' => 'MSG', 'lost_item' => 'LF', 'lost_report' => 'LR', 'budget' => 'BUD', 'key_card' => 'KC', 'upsell' => 'UPS', 'visitor' => 'VIS', 'gate_pass' => 'GP', 'incident' => 'INC', 'wht_cert' => 'WHTC', 'stat_remit' => 'SRM', 'bank_stmt' => 'BST', 'petty_voucher' => 'PCV', 'petty_iou' => 'IOU', 'petty_topup' => 'PCT', 'petty_adj' => 'PCA', 'petty_repl' => 'PCR', 'petty_count' => 'PCC', 'exp_claim' => 'EXC', 'rfq' => 'RFQ', 'grn' => 'GRN', 'tip' => 'TIP', 'sc_dist' => 'SCD', 'fs_waste' => 'FW', 'lease' => 'LSE', 'lease_charge' => 'LCH', 'lease_receipt' => 'LRC', 'club_membership' => 'MB', 'club_charge' => 'CC', 'club_receipt' => 'CR', 'club_daypass' => 'DP', 'rev_auth' => 'AUT', 'accrual' => 'ACR', 'recurring_journal' => 'RJ', 'treasury_transfer' => 'TRF', 'cheque_received' => 'CQR', 'capex_project' => 'CPX', 'activity_booking' => 'ACT', 'activity_settlement' => 'AST', 'sales_opportunity' => 'OPP', 'marketing_event' => 'MKT', 'dm_campaign' => 'DMC', 'dm_influencer' => 'INF', 'bd_partner' => 'PTR', 'bd_initiative' => 'BDI', 'training_session' => 'TRS', 'training_cert' => 'TC', 'cro_enquiry' => 'ENQ', 'qa_audit' => 'QA', 'qa_action' => 'CA', 'hse_drill' => 'FDR', 'hse_risk' => 'RA', 'hse_permit' => 'PTW', 'hse_accident' => 'ACC', 'parking_ticket' => 'PK', 'parking_permit' => 'PP', 'fb_yield' => 'YT', 'talent_vacancy' => 'VAC', 'talent_applicant' => 'APL', 'talent_offer' => 'OFR', 'talent_case' => 'DC', 'talent_grievance' => 'GRV', 'talent_exit' => 'EX', 'esg_waste' => 'WST', 'esg_refrigerant' => 'RFG', 'esg_initiative' => 'ECO', 'esg_community' => 'CSR', 'tv_message' => 'TVM', 'tv_purchase' => 'TVP', 'kiosk_session' => 'KSK', 'partner_request' => 'PAR', 'organiser_request' => 'EOR', 'migration' => 'MIG',
    ];

    public static function next(string $type, ?int $hotelId = null): string
    {
        $hotelId ??= Tenant::id();
        $key = 'seq_'.$type;

        return DB::transaction(function () use ($hotelId, $key, $type) {
            $row = Setting::withoutGlobalScopes()->where('hotel_id', $hotelId)->where('key', $key)->lockForUpdate()->first();
            if (! $row) {
                $row = new Setting(['key' => $key, 'value' => '0']);
                $row->hotel_id = $hotelId;
            }
            $n = ((int) $row->value) + 1;
            $row->value = (string) $n;
            $row->save();
            $prefix = Setting::withoutGlobalScopes()->where('hotel_id', $hotelId)->where('key', 'prefix_'.$type)->value('value')
                ?? (self::PREFIXES[$type] ?? strtoupper($type));

            return $type === 'kot' ? (string) $n : $prefix.'-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
        });
    }
}
