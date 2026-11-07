<?php

use App\Models\GiftVoucher;
use App\Models\ChannelConnection;
use App\Models\Hotel;
use App\Models\MarketingMessage;
use App\Models\OtaChannel;
use App\Models\Kiosk;
use App\Models\ParkingSession;
use App\Models\PosTable;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RfqSupplier;
use App\Models\SurveyInvitation;
use App\Models\TableReservation;
use App\Models\TvDevice;
use App\Models\VehicleTracker;
use App\Models\Visitor;

/*
|--------------------------------------------------------------------------
| v10.4 – which licence module a guest-facing surface belongs to
|--------------------------------------------------------------------------
|
| v10.3 gated the licensed screens: RequirePermission asks the licence, and can_do() asks the
| same question, so the sidebar and the URL agree. That left the other half of the product
| ungated — the pages and endpoints a guest reaches without signing in. A tenant that is not
| licensed for Spa & Activities could still serve its activity vouchers, and one without the
| Channel Manager could still answer its own iCal feed.
|
| This file says which module every one of those surfaces belongs to, so the same licence
| question is asked there too. `surfaces` is keyed by the path prefix the route is served
| under; the longest matching prefix wins, so `/dine/r` (a guest managing a table booking) and
| `/dine` (the booking page itself) can be told apart.
|
| `resolve` says how to find the property BEFORE the controller runs, because the licence
| belongs to the group that owns that property:
|
|   ['code']                                       – the {code} in the path is hotels.code
|   ['token', Model::class, 'column']               – the {token} in the path is a column on that model
|   ['token', Model::class, 'token_hash', 'sha256'] – …and only the SHA-256 of the token is stored
|   null                                            – the surface opens its own link inside the controller
|
| When `resolve` is null the surface is still classified (so the audit can prove nothing public
| is unclassified) and, for GET, it is checked again after the controller has resolved the
| property (`after` => true) — the page is then refused instead of returned, which is why only
| read surfaces are marked that way. A POST never runs its side effect before the check.
|
*/

return [

    /*
    | Guest surfaces that follow the licence.
    */
    'surfaces' => [

        '/book' => [
            'module' => 'Reservations',
            'label' => 'the online booking engine',
            'resolve' => ['code'],
        ],

        '/feedback' => [
            'module' => 'Feedback & Reputation',
            'label' => 'the guest feedback form',
            'resolve' => ['code'],
        ],

        '/survey' => [
            'module' => 'Feedback & Reputation',
            'label' => 'the post-stay survey link',
            'resolve' => ['token', SurveyInvitation::class, 'token_hash', 'sha256'],
            'after' => true,
        ],

        '/api/reviews' => [
            'module' => 'Feedback & Reputation',
            'label' => 'the review-platform webhook',
            'resolve' => ['setting', 'review_webhook_token'],
        ],

        '/dine/r' => [
            'module' => 'Restaurant Reservations',
            'label' => "the guest's table-booking link",
            'resolve' => ['token', TableReservation::class, 'token_hash', 'sha256'],
        ],

        '/dine' => [
            'module' => 'Restaurant Reservations',
            'label' => 'the public table-booking page',
            'resolve' => ['code'],
        ],

        '/v' => [
            'module' => 'Gift Vouchers',
            'label' => 'the public gift-voucher page',
            'resolve' => ['code', GiftVoucher::class, 'code'],
        ],

        '/menu' => [
            'module' => 'POS',
            'label' => 'the QR table menu',
            'resolve' => ['token', PosTable::class, 'qr_token'],
        ],

        '/kiosk/setup' => [
            'module' => 'Self-Service Kiosk',
            'label' => 'kiosk setup from the hotel code',
            'resolve' => ['code'],
        ],

        '/kiosk/pair' => [
            'module' => 'Self-Service Kiosk',
            'label' => 'the kiosk pairing code',
            'resolve' => ['token', Kiosk::class, 'token'],
        ],

        '/kiosk' => [
            'module' => 'Self-Service Kiosk',
            'label' => 'the check-in kiosk screens',
            'resolve' => ['token', Kiosk::class, 'token'],
            'after' => true,
        ],

        '/tv/setup' => [
            'module' => 'IPTV & Guest Welcome',
            'label' => 'TV setup from the hotel code',
            'resolve' => ['code'],
        ],

        '/tv/pair' => [
            'module' => 'IPTV & Guest Welcome',
            'label' => 'the TV pairing code',
            'resolve' => ['token', TvDevice::class, 'token'],
        ],

        '/tv' => [
            'module' => 'IPTV & Guest Welcome',
            'label' => 'the in-room TV portal',
            'resolve' => ['token', TvDevice::class, 'token'],
            'after' => true,
        ],

        '/stay' => [
            'module' => 'Concierge',
            'label' => 'the guest mobile portal',
            'resolve' => ['token', ReservationRoom::class, 'portal_token'],
            'after' => true,
        ],

        '/checkin' => [
            'module' => 'Front Desk',
            'label' => 'the online pre-check-in link',
            'resolve' => ['token', Reservation::class, 'checkin_token'],
        ],

        '/offers' => [
            'module' => 'Upselling',
            'label' => 'the pre-arrival offer link',
            'resolve' => ['token', Reservation::class, 'offer_token'],
        ],

        '/valet' => [
            'module' => 'Parking & Valet',
            'label' => 'the valet request link',
            'resolve' => ['token', ParkingSession::class, 'token'],
            'after' => true,
        ],

        '/visitor-pass' => [
            'module' => 'Security',
            'label' => 'the visitor pre-pass link',
            'resolve' => ['token', Visitor::class, 'pre_token'],
            'after' => true,
        ],

        '/activity-voucher' => [
            'module' => 'Spa & Activities',
            'label' => 'the activity voucher',
            'resolve' => ['token', \App\Models\ActivityBooking::class, 'voucher_token'],
        ],

        '/quote' => [
            'module' => 'Purchasing',
            'label' => 'the supplier quote link',
            'resolve' => ['token', RfqSupplier::class, 'token'],
            'after' => true,
        ],

        '/careers' => [
            'module' => 'Talent Management',
            'label' => 'the public careers page',
            'resolve' => ['code'],
        ],

        '/privacy-notice' => [
            'module' => 'Privacy & Compliance',
            'label' => 'the privacy-notice form',
            'resolve' => ['code'],
        ],

        '/pay' => [
            'module' => 'Online Payments',
            'label' => 'the payment return page',
            'resolve' => ['code'],
        ],

        // /webhooks/pay/{provider}/{code} – the hotel code is the last segment
        '/webhooks/pay' => [
            'module' => 'Online Payments',
            'label' => 'the payment gateway webhook',
            'resolve' => ['code'],
            'segment' => 2,
        ],

        '/webhooks/inbox' => [
            'module' => 'Guest Messaging',
            'label' => 'the messaging provider webhook',
            'resolve' => ['code'],
            'segment' => 2,
        ],

        '/ical' => [
            'module' => 'Channel Manager',
            'label' => 'the iCal export an OTA polls',
            'resolve' => ['token', OtaChannel::class, 'export_token'],
            'strip' => '.ics',
        ],

        '/api/channel' => [
            'module' => 'Channel Manager',
            'label' => 'the channel-manager API',
            'resolve' => ['token', ChannelConnection::class, 'token'],
        ],

        '/api/pbx' => [
            'module' => 'Hotel Interfaces',
            'label' => 'the PBX call-log push',
            'resolve' => ['setting', 'pbx_token'],
        ],

        '/api/fleet' => [
            'module' => 'Transport Desk',
            'label' => 'the fleet GPS ingest',
            'resolve' => ['token', VehicleTracker::class, 'token_hash', 'sha256'],
        ],

        '/api/robita/events' => [
            'module' => 'API & Integrations',
            'label' => 'the Robita event intake',
            'resolve' => null,
            'why' => 'the lock sends its key in the Authorization header, not in the URL: the controller resolves the property and refuses the event with a 403 before it writes a row',
        ],

        '/api/v1' => [
            'module' => 'API & Integrations',
            'label' => 'the REST API',
            'resolve' => null,
            'api' => true, // enforced by ApiAuthenticate, which has the key's hotel
        ],

        '/partner' => [
            'module' => 'Partner Portal',
            'label' => 'the partner portal',
            'resolve' => null,
            'after' => true,
            'why' => 'a partner signs in with their own session: the property is only known once that session is read, so PartnerAuth refuses the whole portal — writes included — the moment it resolves an unlicensed hotel',
        ],

        '/organiser' => [
            'module' => 'Organiser Portal',
            'label' => 'the organiser portal',
            'resolve' => null,
            'after' => true,
            'why' => 'an organiser signs in with their own session: the property is only known once that session is read, so OrganiserAuth refuses the whole portal — writes included — the moment it resolves an unlicensed hotel',
        ],

        '/m' => [
            'module' => 'CRM & Marketing',
            'label' => 'tracked marketing links',
            'resolve' => ['token', MarketingMessage::class, 'token'],
        ],

        '/u' => [
            'module' => 'CRM & Marketing',
            'label' => 'unsubscribe links',
            'resolve' => null,
            // A one-click unsubscribe is sent by the mail client, not the guest: refusing it for an
            // unlicensed CRM would leave people subscribed to mail they asked to stop.
            'why' => 'an unsubscribe must work even when the marketing module is not licensed',
        ],

        '/go' => [
            'module' => 'Digital Marketing',
            'label' => 'short campaign links',
            'resolve' => null,
            'why' => 'a link already printed on a flyer or sent by SMS has to keep resolving',
        ],
    ],

    /*
    | Never refused by a licence, with the reason. Signing in cannot depend on what was bought:
    | a tenant that dropped a module still has to reach the screens that sell it back.
    */
    'always' => [
        '/' => 'the sign-in redirect',
        '/login' => 'a tenant must be able to sign in even with nothing licensed',
        '/logout' => 'signing out is never a licensed act',
        '/up' => 'the health probe',
        '/offline' => 'the service-worker shell, served before anyone signs in',
        '/storage' => 'uploaded files linked from an email already sent',
    ],

    /*
    | /api/v1 – the bearer key names the property, so ApiAuthenticate asks the licence after it has
    | authenticated. Longest prefix wins, like `surfaces`; anything not listed falls to `default`.
    */
    'api' => [
        'default' => 'API & Integrations',
        '/api/v1/rooms' => 'Housekeeping',
        '/api/v1/guests' => 'Guests & CRM',
        '/api/v1/reservations' => 'Reservations',
        '/api/v1/availability' => 'Reservations',
        '/api/v1/rates' => 'Revenue Management',
        '/api/v1/reports' => 'Reports',
        '/api/v1/events' => 'Administration',
    ],
];
