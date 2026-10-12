<?php

use App\Http\Controllers;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/
// Root serves the login page directly (200) for guests; signed-in users go to the dashboard.
Route::get('/', fn () => auth()->check() ? redirect()->to(LoginController::home(auth()->user())) : app(LoginController::class)->show())->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1')->name('login.attempt');
});
Route::middleware('guest')->controller(Controllers\Auth\TwoFactorController::class)->group(function () {
    Route::get('/login/two-factor', 'challenge')->name('login.2fa');
    Route::post('/login/two-factor', 'verify')->middleware('throttle:10,1')->name('login.2fa.verify');
});
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// Online booking engine & guest feedback (public, per hotel code)
Route::get('/book/{code}', [Controllers\BookingEngineController::class, 'show'])->name('booking.show');
Route::post('/book/{code}', [Controllers\BookingEngineController::class, 'store'])->middleware('throttle:10,1')->name('booking.store');
Route::get('/book/{code}/done/{no}', [Controllers\BookingEngineController::class, 'done'])->name('booking.done');
Route::get('/go/{dlcode}', [Controllers\DigitalMarketingController::class, 'go'])->where('dlcode', '[A-Za-z0-9-]{3,40}')->middleware('throttle:120,1')->name('dm.go'); // v6.2 tracked short link
Route::get('/feedback/{code}', [Controllers\FeedbackController::class, 'form'])->name('feedback.form');
Route::post('/feedback/{code}', [Controllers\FeedbackController::class, 'submit'])->middleware('throttle:10,1')->name('feedback.submit');

// v3.8 public gift voucher page: the voucher code is the bearer secret (40 random bits) – throttled against guessing
Route::get('/v/{code}', [Controllers\GiftVoucherController::class, 'publicPage'])->where('code', 'GV-[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}')->middleware('throttle:30,1')->name('voucher.public');

// v2.6 marketing links: tracked booking link + unsubscribe / preferences (token = one marketing message)
Route::get('/m/{token}', [Controllers\MarketingLinkController::class, 'click'])->where('token', '[A-Za-z0-9]{32}')->middleware('throttle:60,1')->name('crm.click');
Route::get('/u/{token}', [Controllers\MarketingLinkController::class, 'preferences'])->where('token', '[A-Za-z0-9]{7,40}')->middleware('throttle:30,1')->name('crm.unsubscribe');
Route::post('/u/{token}', [Controllers\MarketingLinkController::class, 'save'])->where('token', '[A-Za-z0-9]{32}')->middleware('throttle:10,1')->name('crm.unsubscribe.save');
// RFC 8058 one-click unsubscribe: POSTed by the mail client itself – no session / CSRF
Route::post('/u/{token}/one-click', [Controllers\MarketingLinkController::class, 'oneClick'])->where('token', '[A-Za-z0-9]{32}')->middleware('throttle:30,1')
    ->withoutMiddleware([\Illuminate\Session\Middleware\StartSession::class, \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class])->name('crm.unsubscribe.oneclick');

// QR table menu – guest self-ordering (token printed on the table QR code)
// Polled by OTAs every few minutes: stateless – no session row, no cookies, no CSRF.
Route::get('/ical/{token}.ics', [Controllers\ChannelController::class, 'feed'])->where('token', '[A-Za-z0-9]{24,40}')->middleware('throttle:120,1')
    ->withoutMiddleware([\Illuminate\Session\Middleware\StartSession::class, \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class, \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class])->name('ical.feed');
// v2.8 channel manager API – called by OTAs / the channel hub: stateless, HMAC-signed, per-connection token
Route::prefix('api/channel/{token}')->where(['token' => '[A-Za-z0-9]{40}'])->controller(Controllers\ChannelApiController::class)->middleware('throttle:240,1')
    ->withoutMiddleware([\Illuminate\Session\Middleware\StartSession::class, \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class, \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class])->name('channel.api.')->group(function () {
        Route::post('/reservations', 'reservations')->name('reservations');
        Route::get('/ari', 'ari')->name('ari');
    });
// v3.7 PBX / call-logger push of call detail records: stateless, per-hotel 40-character token (Interfaces › PBX push)
Route::post('/api/pbx/{token}', [Controllers\InterfaceController::class, 'push'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:120,1')
    ->withoutMiddleware([\Illuminate\Session\Middleware\StartSession::class, \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class, \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class])->name('pbx.api.push');
// v4.0 REST API v1 – stateless JSON (bearer key per client; scopes, IP allow-list, per-key rate limit – see ApiAuthenticate)
Route::prefix('api/v1')->name('apiv1.')->controller(Controllers\Api\V1Controller::class)
    ->withoutMiddleware([\Illuminate\Session\Middleware\StartSession::class, \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class, \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class])->group(function () {
        Route::get('/openapi.json', 'openapi')->middleware('throttle:60,1')->name('openapi');
        Route::middleware('api.client')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/hotel', 'hotel')->name('hotel');
            Route::get('/events', 'events')->name('events');
            Route::get('/availability', 'availability')->middleware('api.scope:availability:read')->name('availability');
            Route::get('/rates', 'rates')->middleware('api.scope:rates:read')->name('rates');
            Route::get('/reservations', 'reservations')->middleware('api.scope:reservations:read')->name('reservations.index');
            Route::post('/reservations', 'storeReservation')->middleware('api.scope:reservations:write')->name('reservations.store');
            Route::get('/reservations/{no}', 'reservation')->where('no', '[A-Za-z0-9_\-.]{1,30}')->middleware('api.scope:reservations:read')->name('reservations.show');
            Route::post('/reservations/{no}/cancel', 'cancelReservation')->where('no', '[A-Za-z0-9_\-.]{1,30}')->middleware('api.scope:reservations:write')->name('reservations.cancel');
            Route::get('/reservations/{no}/folio', 'folio')->where('no', '[A-Za-z0-9_\-.]{1,30}')->middleware('api.scope:folios:read')->name('reservations.folio');
            Route::get('/rooms', 'rooms')->middleware('api.scope:rooms:read')->name('rooms.index');
            Route::patch('/rooms/{roomNo}', 'updateRoom')->where('roomNo', '[A-Za-z0-9\-]{1,20}')->middleware('api.scope:housekeeping:write')->name('rooms.update');
            Route::get('/guests', 'guests')->middleware('api.scope:guests:read')->name('guests.index');
            Route::get('/guests/{id}', 'guest')->whereNumber('id')->middleware('api.scope:guests:read')->name('guests.show');
            Route::get('/reports/daily', 'daily')->middleware('api.scope:reports:read')->name('reports.daily');
        });
    });
// v3.1 post-stay guest survey (unguessable per-invitation link; only its SHA-256 is stored)
Route::get('/survey/{token}', [Controllers\SurveyPublicController::class, 'show'])->where('token', '[a-f0-9]{40}')->middleware('throttle:30,1')->name('survey.show');
Route::post('/survey/{token}', [Controllers\SurveyPublicController::class, 'submit'])->where('token', '[a-f0-9]{40}')->middleware('throttle:10,1')->name('survey.submit');
Route::get('/survey/{token}/review/{site}', [Controllers\SurveyPublicController::class, 'review'])->where(['token' => '[a-f0-9]{40}', 'site' => 'google|tripadvisor'])->middleware('throttle:10,1')->name('survey.review');

// v3.2 restaurant table booking (public page per hotel code) and the guest's manage / cancel link (only its SHA-256 is stored)
Route::get('/dine/r/{token}', [Controllers\DinePublicController::class, 'manage'])->where('token', '[a-f0-9]{40}')->middleware('throttle:30,1')->name('dine.manage');
Route::post('/dine/r/{token}/cancel', [Controllers\DinePublicController::class, 'cancel'])->where('token', '[a-f0-9]{40}')->middleware('throttle:10,1')->name('dine.cancel');
Route::get('/dine/{code}', [Controllers\DinePublicController::class, 'show'])->where('code', '[A-Za-z0-9_-]{2,20}')->middleware('throttle:60,1,dine-view')->name('dine.show');
Route::post('/dine/{code}', [Controllers\DinePublicController::class, 'store'])->where('code', '[A-Za-z0-9_-]{2,20}')->middleware('throttle:10,1,dine-book')->name('dine.store');

// v6.9 public careers page: open vacancies and the application form (CV upload). The throttle prefixes keep separate counters –
// unprefixed limiters share one key per IP, so browsing the jobs would use up the five applications a minute
Route::get('/careers/{code}', [Controllers\CareersPublicController::class, 'index'])->where('code', '[A-Za-z0-9_-]{2,20}')->middleware('throttle:60,1,careers-view')->name('careers.index');
Route::get('/careers/{code}/{job}', [Controllers\CareersPublicController::class, 'show'])->where(['code' => '[A-Za-z0-9_-]{2,20}', 'job' => '[0-9]+'])->middleware('throttle:60,1,careers-view')->name('careers.show');
Route::post('/careers/{code}/{job}', [Controllers\CareersPublicController::class, 'apply'])->where(['code' => '[A-Za-z0-9_-]{2,20}', 'job' => '[0-9]+'])->middleware('throttle:5,1,careers-apply')->name('careers.apply');

// v2.9 privacy notice & data-principal request form (per hotel code); the request is confirmed through an e-mailed link
Route::get('/privacy-notice/{code}', [Controllers\PrivacyPublicController::class, 'show'])->where('code', '[A-Za-z0-9_-]{2,20}')->name('privacy.public');
Route::post('/privacy-notice/{code}', [Controllers\PrivacyPublicController::class, 'submit'])->where('code', '[A-Za-z0-9_-]{2,20}')->middleware('throttle:5,1')->name('privacy.public.submit');
Route::get('/privacy-notice/{code}/verify/{token}', [Controllers\PrivacyPublicController::class, 'verify'])->where(['code' => '[A-Za-z0-9_-]{2,20}', 'token' => '[A-Za-z0-9]{40}'])
    ->middleware('throttle:20,1')->name('privacy.public.verify');
// Online (pre-arrival) check-in – tokenised link sent with the confirmation
Route::get('/checkin/{token}', [Controllers\PreCheckinController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('checkin.show');
Route::post('/checkin/{token}', [Controllers\PreCheckinController::class, 'submit'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:6,1')->name('checkin.submit');
// v4.1 – guest's private "enhance your stay" offers page (pre-arrival e-mail, online check-in, guest portal)
Route::get('/offers/{token}', [Controllers\UpsellPublicController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('offers.show');
Route::post('/offers/{token}', [Controllers\UpsellPublicController::class, 'order'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1')->name('offers.order');
Route::post('/offers/{token}/cancel/{oid}', [Controllers\UpsellPublicController::class, 'cancel'])->where(['token' => '[A-Za-z0-9]{40}', 'oid' => '[0-9]+'])->middleware('throttle:10,1')->name('offers.cancel');
// v4.8 – supplier's private quotation page (link in the RFQ e-mail)
Route::get('/quote/{token}', [Controllers\RfqPublicController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('rfq.public');
Route::post('/quote/{token}', [Controllers\RfqPublicController::class, 'submit'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1')->name('rfq.public.submit');
Route::post('/quote/{token}/decline', [Controllers\RfqPublicController::class, 'decline'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:5,1')->name('rfq.public.decline');
// v6.6 – valet ticket QR: the guest sees where the car is and calls for it
Route::get('/valet/{token}', [Controllers\ParkingController::class, 'publicPage'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('parking.public');
Route::post('/valet/{token}/request', [Controllers\ParkingController::class, 'publicRequest'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:6,1')->name('parking.public.request');
// v5.9 – guest's activity voucher (link in the confirmation SMS / e-mail) – no room number
Route::get('/activity-voucher/{token}', [Controllers\ConciergeController::class, 'publicVoucher'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('concierge.voucher.public');
// v4.2 – visitor pass a guest shares with their visitor (QR shown at the security desk)
Route::get('/visitor-pass/{token}', [Controllers\VisitorPassController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1')->name('visitor.pass');
Route::get('/menu/{token}', [Controllers\QrMenuController::class, 'show'])->where('token', '[A-Za-z0-9]{16,40}')->name('qrmenu.show');
Route::post('/menu/{token}', [Controllers\QrMenuController::class, 'order'])->where('token', '[A-Za-z0-9]{16,40}')->middleware('throttle:6,1')->name('qrmenu.order');
Route::get('/menu/{token}/status', [Controllers\QrMenuController::class, 'status'])->where('token', '[A-Za-z0-9]{16,40}')->middleware('throttle:60,1')->name('qrmenu.status');
// v7.2 Self-service lobby kiosk (stateless like the TVs: the kiosk's 40-character token identifies it, each guest visit has its own token)
Route::prefix('kiosk')->name('kiosk.')->controller(Controllers\KioskController::class)
    ->withoutMiddleware([\Illuminate\Session\Middleware\StartSession::class, \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class, \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class])->group(function () {
        Route::get('/setup/{code}', 'setup')->where('code', '[A-Za-z0-9_-]{2,20}')->middleware('throttle:30,1')->name('setup');
        Route::post('/setup/{code}', 'pairStart')->where('code', '[A-Za-z0-9_-]{2,20}')->middleware('throttle:kiosk-pair')->name('pair.start');
        Route::get('/pair/{token}', 'pairStatus')->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:60,1')->name('pair.status');
        Route::prefix('{token}')->where(['token' => '[A-Za-z0-9]{40}'])->group(function () {
            Route::middleware('throttle:kiosk')->group(function () {
                Route::get('/', 'app')->name('app');
                Route::get('/home', 'home')->name('home');
                Route::get('/v/{visit}', 'state')->where('visit', '[A-Za-z0-9]{40}')->name('state');
                Route::get('/v/{visit}/pay-status', 'payStatus')->where('visit', '[A-Za-z0-9]{40}')->name('pay.status');
                Route::get('/v/{visit}/walkin-offer', 'walkinOffer')->where('visit', '[A-Za-z0-9]{40}')->name('walkin.offer');
            });
            Route::middleware('throttle:kiosk-write')->group(function () {
                Route::post('/start', 'start')->name('start');
                Route::post('/v/{visit}/{action}', 'act')->where(['visit' => '[A-Za-z0-9]{40}', 'action' => 'find|details|document|sign|pay|room|keys|checkout|feedback|invoice|assist|cancel|walkin'])->name('act');
            });
        });
    });
// v7.1 In-room TV portal – hotel TVs / lobby screens (stateless: the 40-character device token in the start URL identifies the set)
Route::prefix('tv')->name('tv.')->controller(Controllers\TvController::class)
    ->withoutMiddleware([\Illuminate\Session\Middleware\StartSession::class, \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class, \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class])->group(function () {
        Route::get('/setup/{code}', 'setup')->where('code', '[A-Za-z0-9_-]{2,20}')->middleware('throttle:30,1')->name('setup');
        Route::post('/setup/{code}', 'pairStart')->where('code', '[A-Za-z0-9_-]{2,20}')->middleware('throttle:tv-pair')->name('pair.start');
        Route::get('/pair/{token}', 'pairStatus')->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:60,1')->name('pair.status');
        Route::prefix('{token}')->where(['token' => '[A-Za-z0-9]{40}'])->group(function () {
            Route::middleware('throttle:tv')->group(function () {
                Route::get('/', 'app')->name('app');
                Route::get('/state', 'state')->name('state');
                Route::get('/data/{section}', 'data')->where('section', 'channels|movies|dining|services|pages|explore|messages')->name('data');
            });
            Route::middleware('throttle:tv-write')->group(function () {
                Route::post('/welcome', 'welcome')->name('welcome');
                Route::post('/language', 'language')->name('language');
                Route::post('/event', 'event')->name('event');
                Route::post('/read/{id}', 'read')->whereNumber('id')->name('read');
                Route::post('/ack/{id}', 'ack')->whereNumber('id')->name('ack');
                Route::post('/rent/{movie}', 'rent')->whereNumber('movie')->name('rent');
                Route::post('/order', 'order')->name('order');
                Route::post('/request', 'request')->name('request');
                Route::post('/wakeup', 'wakeup')->name('wakeup');
                Route::post('/bill', 'bill')->name('bill');
                Route::post('/checkout', 'checkout')->name('checkout');
                Route::post('/feedback', 'feedback')->name('feedback');
            });
        });
    });
// Guest mobile portal (v1.7) – private per-stay link sent at check-in / printed on the welcome card
Route::prefix('stay/{token}')->where(['token' => '[A-Za-z0-9]{40}'])->controller(Controllers\GuestPortalController::class)->name('portal.')->group(function () {
    Route::middleware('throttle:portal')->group(function () {
        Route::get('/', 'home')->name('home');
        Route::get('/requests', 'requests')->name('requests');
        Route::get('/dining', 'dining')->name('dining');
        Route::get('/spa', 'spa')->name('spa');
        Route::get('/transport', 'transport')->name('transport');
        Route::get('/bill', 'bill')->name('bill');
        Route::get('/exchange', 'exchange')->name('exchange');
        Route::get('/messages', 'messages')->name('messages');
        Route::get('/visitors', 'visitors')->name('visitors');   // v4.2
        Route::get('/explore', 'explore')->name('explore');      // v5.9 activities & local guide
        Route::get('/chat', 'chat')->name('chat');               // v7.3 chat with the hotel
    });
    Route::get('/chat/poll', 'chatPoll')->middleware('throttle:portal-chat')->name('chat.poll');
    Route::middleware('throttle:portal-write')->group(function () {
        Route::post('/requests', 'storeRequest')->name('requests.store');
        Route::post('/requests/{rid}/cancel', 'cancelRequest')->whereNumber('rid')->name('requests.cancel');
        Route::post('/requests/{rid}/rate', 'rateRequest')->whereNumber('rid')->name('requests.rate');
        Route::post('/dining', 'orderDining')->name('dining.order');
        Route::post('/spa', 'bookSpa')->name('spa.book');
        Route::post('/spa/{aid}/cancel', 'cancelSpa')->whereNumber('aid')->name('spa.cancel');
        Route::post('/transport', 'requestTransport')->name('transport.request');
        Route::post('/transport/{tid}/cancel', 'cancelTransport')->whereNumber('tid')->name('transport.cancel');
        Route::post('/checkout', 'expressCheckout')->name('checkout');
        Route::post('/pay', 'payOnline')->name('pay');
        Route::post('/feedback', 'feedback')->name('feedback');
        Route::post('/explore', 'bookActivity')->name('explore.book');                                        // v5.9
        Route::post('/explore/{bid}/cancel', 'cancelActivity')->whereNumber('bid')->name('explore.cancel');
        Route::post('/visitors', 'registerVisitor')->name('visitors.store');                                  // v4.2
        Route::post('/visitors/{vid}/cancel', 'cancelVisitor')->whereNumber('vid')->name('visitors.cancel');
        Route::post('/visitors/preference', 'visitorPolicy')->name('visitors.policy');
        Route::post('/green', 'green')->name('green');                                                        // v7.0 green-stay choice
        Route::post('/chat', 'chatSend')->name('chat.send');                                                  // v7.3
        Route::post('/chat/rate', 'chatRate')->name('chat.rate');
    });
});
// Online payments (v2.1) – public payment page per link; card details are entered on the gateway's hosted page
Route::prefix('pay/{token}')->where(['token' => '[A-Za-z0-9]{40}'])->controller(Controllers\PayController::class)->name('pay.')->group(function () {
    Route::middleware('throttle:portal')->group(function () {
        Route::get('/', 'show')->name('show');
        Route::get('/return', 'back')->name('return');
        Route::get('/demo', 'demo')->name('demo');
    });
    Route::middleware('throttle:portal-write')->group(function () {
        Route::post('/start', 'start')->name('start');
        Route::post('/demo', 'demoSubmit')->name('demo.submit');
    });
});
// v7.3 inbound guest messages (SMS / WhatsApp gateway, inbound e-mail) – stateless, no CSRF; authenticated by the provider's signature
// v7.4 corporate & travel-agent portal (B2B extranet): its own session ('partner' middleware), never the staff guard.
// Token links (invitation / reset) are 48 random characters; only their SHA-256 is stored.
Route::prefix('partner')->name('partner.')->controller(Controllers\PartnerPortalController::class)->group(function () {
    Route::get('/login', 'loginForm')->middleware('throttle:60,1,partner-view')->name('login');
    Route::post('/login', 'login')->middleware('throttle:10,1,partner-login')->name('login.attempt');
    Route::get('/forgot', 'forgotForm')->middleware('throttle:60,1,partner-view')->name('forgot');
    Route::post('/forgot', 'forgot')->middleware('throttle:5,1,partner-forgot')->name('forgot.send');
    Route::get('/invite/{token}', 'inviteForm')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1,partner-token')->name('invite');
    Route::post('/invite/{token}', 'inviteAccept')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1,partner-token-save')->name('invite.accept');
    Route::get('/reset/{token}', 'resetForm')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1,partner-token')->name('reset');
    Route::post('/reset/{token}', 'reset')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1,partner-token-save')->name('reset.save');
    Route::middleware(['partner', 'throttle:240,1,partner-app'])->group(function () {
        Route::get('/', 'home')->name('home');
        Route::post('/logout', 'logout')->name('logout');
        Route::get('/book', 'book')->name('book');
        Route::post('/book', 'store')->middleware('throttle:20,1,partner-book')->name('book.store');
        Route::get('/bookings', 'bookings')->name('bookings');
        Route::get('/bookings/{id}', 'booking')->whereNumber('id')->name('booking');
        Route::post('/bookings/{id}/cancel', 'cancel')->whereNumber('id')->name('booking.cancel');
        Route::post('/bookings/{id}/amend', 'amend')->whereNumber('id')->name('booking.amend');
        Route::get('/requests', 'requests')->name('requests');
        Route::post('/requests/group', 'group')->name('requests.group');
        Route::post('/requests/{id}/withdraw', 'withdraw')->whereNumber('id')->name('requests.withdraw');
        Route::get('/statement', 'statement')->name('statement');
        Route::get('/invoices/{folio}', 'invoice')->whereNumber('folio')->name('invoice');
        Route::post('/pay', 'pay')->middleware('throttle:10,1,partner-pay')->name('pay');
        Route::get('/commissions', 'commissions')->name('commissions');
        Route::get('/users', 'users')->name('users');
        Route::post('/users', 'invite')->middleware('throttle:20,1,partner-invite')->name('users.invite');
        Route::post('/users/{id}', 'userAction')->whereNumber('id')->name('users.action');
        Route::get('/profile', 'profile')->name('profile');
        Route::post('/profile/password', 'password')->middleware('throttle:10,1,partner-pw')->name('password');
    });
});

// v7.5 event organiser portal (B2B extranet for MICE & banquet clients): its own session ('organiser' middleware), never the staff guard.
// Token links (invitation / reset) are 48 random characters; only their SHA-256 is stored.
Route::prefix('organiser')->name('organiser.')->controller(Controllers\OrganiserPortalController::class)->group(function () {
    Route::get('/login', 'loginForm')->middleware('throttle:60,1,organiser-view')->name('login');
    Route::post('/login', 'login')->middleware('throttle:10,1,organiser-login')->name('login.attempt');
    Route::get('/forgot', 'forgotForm')->middleware('throttle:60,1,organiser-view')->name('forgot');
    Route::post('/forgot', 'forgot')->middleware('throttle:5,1,organiser-forgot')->name('forgot.send');
    Route::get('/invite/{token}', 'inviteForm')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1,organiser-token')->name('invite');
    Route::post('/invite/{token}', 'inviteAccept')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1,organiser-token-save')->name('invite.accept');
    Route::get('/reset/{token}', 'resetForm')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1,organiser-token')->name('reset');
    Route::post('/reset/{token}', 'reset')->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1,organiser-token-save')->name('reset.save');
    Route::middleware(['organiser', 'throttle:240,1,organiser-app'])->group(function () {
        Route::get('/', 'home')->name('home');
        Route::post('/logout', 'logout')->name('logout');
        Route::get('/events', 'events')->name('events');
        Route::get('/events/{id}', 'event')->whereNumber('id')->name('event');
        Route::get('/events/{id}/beo', 'beo')->whereNumber('id')->name('beo');
        Route::post('/events/{id}/beo/sign', 'beoSign')->whereNumber('id')->middleware('throttle:20,1,organiser-beo')->name('beo.sign');
        Route::post('/events/{id}/guarantee', 'guarantee')->whereNumber('id')->middleware('throttle:20,1,organiser-guarantee')->name('guarantee');
        Route::post('/events/{id}/change', 'change')->whereNumber('id')->middleware('throttle:20,1,organiser-change')->name('change');
        Route::post('/events/{id}/cancel', 'cancelReq')->whereNumber('id')->middleware('throttle:10,1,organiser-cancel')->name('cancel');
        Route::post('/events/{id}/pay', 'pay')->whereNumber('id')->middleware('throttle:10,1,organiser-pay')->name('pay');
        Route::get('/events/{id}/guests', 'attendees')->whereNumber('id')->name('attendees');
        Route::post('/events/{id}/guests', 'attendeeStore')->whereNumber('id')->middleware('throttle:60,1,organiser-guests')->name('attendees.store');
        Route::post('/events/{id}/guests/{aid}/delete', 'attendeeDelete')->whereNumber(['id', 'aid'])->name('attendees.delete');
        Route::get('/requests', 'requests')->name('requests');
        Route::post('/requests/quote', 'quote')->middleware('throttle:20,1,organiser-quote')->name('requests.quote');
        Route::post('/requests/{id}/withdraw', 'withdraw')->whereNumber('id')->name('requests.withdraw');
        Route::get('/users', 'users')->name('users');
        Route::post('/users', 'invite')->middleware('throttle:20,1,organiser-invite')->name('users.invite');
        Route::post('/users/{id}', 'userAction')->whereNumber('id')->name('users.action');
        Route::get('/profile', 'profile')->name('profile');
        Route::post('/profile/password', 'password')->middleware('throttle:10,1,organiser-pw')->name('password');
    });
});

Route::post('/webhooks/inbox/{provider}/{code}', [Controllers\InboxController::class, 'webhook'])->where(['provider' => 'generic|twilio', 'code' => '[A-Za-z0-9_-]{2,20}'])->middleware('throttle:240,1')
    ->withoutMiddleware([\Illuminate\Session\Middleware\StartSession::class, \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class, \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class])->name('inbox.webhook');
// Gateway → server notifications: stateless, no CSRF; authenticated by the provider's HMAC signature
Route::post('/webhooks/pay/{provider}/{code}', [Controllers\PayController::class, 'webhook'])->where(['provider' => '[a-z]+', 'code' => '[A-Za-z0-9_-]{2,20}'])->middleware('throttle:240,1')
    ->withoutMiddleware([\Illuminate\Session\Middleware\StartSession::class, \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class, \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class])->name('pay.webhook');

/*
|--------------------------------------------------------------------------
| Application (authenticated + tenant-scoped)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'tenant', 'twofactor'])->group(function () {
    Route::controller(Controllers\Auth\TwoFactorController::class)->prefix('profile/two-factor')->name('profile.2fa')->group(function () {
        Route::get('/', 'show');
        Route::post('/', 'enable')->middleware('throttle:10,1')->name('.enable');
        Route::post('/recovery-codes', 'recovery')->name('.recovery');
        Route::post('/disable', 'disable')->name('.disable');
    });
    Route::post('/switch-hotel', [LoginController::class, 'switchHotel'])->name('switch.hotel');
    Route::get('/profile', [Controllers\UserController::class, 'profile'])->name('profile');
    Route::post('/profile', [Controllers\UserController::class, 'updateProfile'])->name('profile.update');

    Route::get('/Dashboard', [Controllers\DashboardController::class, 'index'])->middleware('perm:dashboard.view')->name('dashboard');
    Route::get('/search', [Controllers\DashboardController::class, 'search'])->name('search');
    Route::get('/search/suggest', [Controllers\DashboardController::class, 'suggest'])->name('search.suggest');
    Route::get('/group-dashboard', [Controllers\GroupDashboardController::class, 'index'])->middleware('perm:group.dashboard')->name('group.dashboard');

    // ---- Rate management (seasonal pricing, weekend rates, min-stay, stop-sell)
    Route::prefix('rates')->name('rates.')->middleware('perm:reservations.rates')->controller(Controllers\RateController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::delete('/{override}', 'destroy')->name('destroy');
    });

    // ---- v1.9 revenue management (forecast, pickup, dynamic pricing)
    // v4.2 security & gate management
    Route::prefix('security')->name('security.')->group(function () {
        Route::controller(Controllers\SecurityController::class)->group(function () {
            Route::get('/visitors', 'index')->middleware('perm:security.view')->name('visitors');
            Route::middleware('perm:security.visitors')->group(function () {
                Route::post('/visitors', 'store')->name('visitors.store');
                Route::post('/visitors/{visitor}/approve', 'approve')->name('visitors.approve');
                Route::post('/visitors/{visitor}/deny', 'deny')->name('visitors.deny');
                Route::post('/visitors/{visitor}/arrive', 'arrive')->name('visitors.arrive');
                Route::post('/visitors/{visitor}/out', 'checkOut')->name('visitors.out');
                Route::post('/visitors/{visitor}/cancel', 'cancel')->name('visitors.cancel');
                Route::post('/visitors/{visitor}/extend', 'extend')->name('visitors.extend');
                Route::get('/visitors/{visitor}/badge', 'badge')->name('visitors.badge');
                Route::post('/stay/{stay}/visitor-policy', 'policy')->name('policy');
            });
            Route::middleware('perm:security.manage')->group(function () {
                Route::post('/watchlist', 'storeWatch')->name('watch.store');
                Route::post('/watchlist/{watch}', 'updateWatch')->name('watch.update');
                Route::post('/watchlist/{watch}/toggle', 'toggleWatch')->name('watch.toggle');
                Route::post('/settings', 'settings')->name('settings');
                Route::post('/run', 'run')->middleware('throttle:6,1')->name('run');
            });
        });
        Route::controller(Controllers\GatePassController::class)->prefix('gate-passes')->name('gp.')->group(function () {
            Route::get('/', 'index')->middleware('perm:security.view,security.gatepass')->name('index');
            Route::get('/new', 'create')->middleware('perm:security.gatepass')->name('create');
            Route::post('/', 'store')->middleware('perm:security.gatepass')->name('store');
            Route::get('/{gatepass}', 'show')->middleware('perm:security.view,security.gatepass')->name('show');
            Route::get('/{gatepass}/print', 'print')->middleware('perm:security.view,security.gatepass')->name('print');
            Route::post('/{gatepass}/cancel', 'cancel')->middleware('perm:security.gatepass,security.approve')->name('cancel');
            Route::middleware('perm:security.approve')->group(function () {
                Route::post('/{gatepass}/approve', 'approve')->name('approve');
                Route::post('/{gatepass}/reject', 'reject')->name('reject');
                Route::post('/{gatepass}/close', 'close')->name('close');
                Route::post('/{gatepass}/extend', 'extend')->name('extend');
            });
            Route::middleware('perm:security.gate')->group(function () {
                Route::post('/{gatepass}/out', 'out')->name('out');
                Route::post('/{gatepass}/receive', 'receive')->name('receive');
            });
        });
        Route::controller(Controllers\IncidentController::class)->prefix('incidents')->group(function () {
            Route::get('/', 'index')->middleware('perm:security.view,security.incidents')->name('incidents');
            Route::post('/', 'store')->middleware('perm:security.incidents')->name('incidents.store');
            Route::get('/{incident}', 'show')->middleware('perm:security.view,security.incidents')->name('incidents.show');
            Route::middleware('perm:security.incidents')->group(function () {
                Route::post('/{incident}', 'update')->name('incidents.update');
                Route::post('/{incident}/note', 'note')->name('incidents.note');
            });
            Route::middleware('perm:security.manage')->group(function () {
                Route::post('/{incident}/close', 'close')->name('incidents.close');
                Route::post('/{incident}/reopen', 'reopen')->name('incidents.reopen');
            });
        });
    });

    // v4.1 upselling & pre-arrival offers
    Route::prefix('upselling')->name('upsell.')->controller(Controllers\UpsellController::class)->group(function () {
        Route::get('/', 'index')->middleware('perm:upsell.view')->name('index');
        Route::middleware('perm:upsell.sell')->group(function () {
            Route::post('/stay/{stay}/sell', 'sell')->name('sell');
            Route::post('/orders/{uorder}/confirm', 'confirm')->name('confirm');
            Route::post('/orders/{uorder}/decline', 'decline')->name('decline');
            Route::post('/orders/{uorder}/cancel', 'cancel')->name('cancel');
            Route::post('/reservations/{reservation}/send', 'sendOffers')->middleware('throttle:10,1')->name('send');
        });
        Route::middleware('perm:upsell.manage')->group(function () {
            Route::post('/offers', 'storeOffer')->name('offers.store');
            Route::post('/offers/{uoffer}', 'updateOffer')->name('offers.update');
            Route::post('/offers/{uoffer}/toggle', 'toggleOffer')->name('offers.toggle');
            Route::post('/offers/{uoffer}/delete', 'destroyOffer')->name('offers.delete');
            Route::post('/run', 'run')->middleware('throttle:6,1')->name('run');
            Route::post('/settings', 'settings')->name('settings');
        });
    });
    Route::prefix('revenue')->name('revenue.')->controller(Controllers\RevenueController::class)->group(function () {
        Route::middleware('perm:revenue.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/pricing', 'pricing')->name('pricing');
            Route::get('/rules', 'rules')->name('rules');
        });
        Route::middleware('perm:revenue.manage')->group(function () {
            Route::post('/pricing/publish', 'publish')->name('publish');
            Route::post('/pricing/reset', 'reset')->name('reset');
            Route::post('/settings', 'settings')->name('settings');
            Route::post('/limits', 'limits')->name('limits');
            Route::get('/rules/create', 'createRule')->name('rules.create');
            Route::post('/rules', 'storeRule')->name('rules.store');
            Route::get('/rules/{yrule}/edit', 'editRule')->name('rules.edit');
            Route::put('/rules/{yrule}', 'updateRule')->name('rules.update');
            Route::post('/rules/{yrule}/toggle', 'toggleRule')->name('rules.toggle');
            Route::delete('/rules/{yrule}', 'destroyRule')->name('rules.destroy');
            Route::post('/snapshot', 'snapshot')->name('snapshot');
        });
    });

    // ---- Kitchen display
    Route::middleware('perm:pos.kitchen')->controller(Controllers\KitchenController::class)->prefix('kitchen')->name('kitchen.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/feed', 'feed')->name('feed');
        Route::post('/bump', 'bump')->name('bump');
        Route::get('/station', 'station')->name('station'); // v7.6 one PC, one kitchen printer
        Route::get('/station/jobs', 'stationJobs')->name('station.jobs');
        Route::post('/station/claim', 'stationClaim')->name('station.claim');
        Route::post('/station/ack', 'stationAck')->name('station.ack');
    });

    // ---- Group room blocks (allotments)
    Route::prefix('blocks')->name('blocks.')->controller(Controllers\BlockController::class)->group(function () {
        Route::get('/', 'index')->middleware('perm:reservations.view')->name('index');
        Route::get('/create', 'create')->middleware('perm:reservations.create')->name('create');
        Route::post('/', 'store')->middleware('perm:reservations.create')->name('store');
        Route::get('/{block}', 'show')->middleware('perm:reservations.view')->name('show');
        Route::get('/{block}/edit', 'edit')->middleware('perm:reservations.edit')->name('edit');
        Route::put('/{block}', 'update')->middleware('perm:reservations.edit')->name('update');
        Route::post('/{block}/pickup-preview', 'previewPickup')->middleware('perm:reservations.view')->name('pickup.preview');
        Route::post('/{block}/pickup', 'pickup')->middleware('perm:reservations.create')->name('pickup');
        Route::post('/{block}/status', 'status')->middleware('perm:reservations.edit')->name('status');
    });

    // ---- v2.8 two-way channel manager (OTA connectivity)
    Route::prefix('channel-manager')->name('cm.')->group(function () {
        Route::controller(Controllers\ChannelManagerController::class)->group(function () {
            Route::middleware('perm:channels.view')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/inbox', 'inbox')->name('inbox');
                Route::get('/inbox/{chmsg}', 'message')->name('message');
                Route::get('/sync-log', 'pushes')->name('pushes');
                Route::get('/parity', 'parity')->name('parity');
            });
            Route::post('/inbox/{chmsg}/resolve', 'resolve')->middleware('perm:channels.resolve')->name('resolve');
            Route::post('/reservations/{reservation}/resolve', 'resolveReservation')->middleware('perm:channels.resolve')->name('reservation.resolve');
            Route::post('/inbox/{chmsg}/reprocess', 'reprocess')->middleware('perm:channels.manage')->name('reprocess');
        });
        Route::get('/connections/{chconn}', [Controllers\ChannelConnectionController::class, 'show'])->middleware('perm:channels.view')->whereNumber('chconn')->name('connections.show');
        Route::prefix('connections')->name('connections.')->middleware('perm:channels.manage')->controller(Controllers\ChannelConnectionController::class)->group(function () {
            Route::get('/create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('/{chconn}/edit', 'edit')->name('edit');
            Route::put('/{chconn}', 'update')->name('update');
            Route::delete('/{chconn}', 'destroy')->name('destroy');
            Route::post('/{chconn}/mapping', 'mapping')->name('mapping');
            Route::post('/{chconn}/test', 'test')->name('test');
            Route::post('/{chconn}/restrictions', 'restrict')->name('restrict');
            Route::delete('/{chconn}/restrictions/{chrestr}', 'unrestrict')->name('unrestrict');
            Route::post('/{chconn}/push', 'push')->name('push');
            Route::post('/{chconn}/rotate', 'rotate')->name('rotate');
            Route::post('/{chconn}/simulate', 'simulate')->name('simulate');
            Route::post('/{chconn}/pull', 'pull')->name('pull');
        });
    });

    // ---- v2.9 guest data privacy & compliance (DPDP / GDPR)
    Route::post('/guests/{guest}/reveal', [Controllers\PrivacyController::class, 'reveal'])->middleware(['perm:pii.reveal', 'throttle:30,1'])->name('guests.reveal');
    Route::prefix('privacy')->name('privacy.')->group(function () {
        Route::controller(Controllers\PrivacyController::class)->group(function () {
            Route::middleware('perm:privacy.view')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/access-log', 'accessLog')->name('access');
                Route::get('/retention', 'retention')->name('retention');
            });
            Route::get('/guests/{guest}/export', 'guestExport')->middleware('perm:privacy.handle')->name('guests.export');
            Route::middleware('perm:privacy.erase')->group(function () {
                Route::post('/retention/settings', 'saveSettings')->name('settings');
                Route::post('/retention/run', 'runRetention')->name('retention.run');
            });
        });
        Route::controller(Controllers\PrivacyRequestController::class)->prefix('requests')->name('requests.')->group(function () {
            Route::middleware('perm:privacy.handle')->group(function () {
                Route::get('/create', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::post('/{preq}/verify', 'verify')->name('verify');
                Route::post('/{preq}/link', 'link')->name('link');
                Route::post('/{preq}/note', 'note')->name('note');
                Route::post('/{preq}/assign', 'assign')->name('assign');
                Route::post('/{preq}/extend', 'extend')->name('extend');
                Route::post('/{preq}/consent', 'consent')->name('consent');
                Route::post('/{preq}/complete', 'complete')->name('complete');
                Route::post('/{preq}/reject', 'reject')->name('reject');
                Route::get('/{preq}/export', 'export')->name('export');
                Route::get('/{preq}/report', 'report')->name('report');
            });
            Route::get('/{preq}', 'show')->middleware('perm:privacy.view')->whereNumber('preq')->name('show');
            Route::post('/{preq}/erase', 'erase')->middleware('perm:privacy.erase')->name('erase');
        });
        Route::controller(Controllers\PrivacyBreachController::class)->prefix('breaches')->name('breaches.')->group(function () {
            Route::middleware('perm:privacy.view')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/{breach}', 'show')->whereNumber('breach')->name('show');
            });
            Route::middleware('perm:privacy.breach')->group(function () {
                Route::get('/create', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('/{breach}/edit', 'edit')->name('edit');
                Route::put('/{breach}', 'update')->name('update');
                Route::post('/{breach}/contain', 'contain')->name('contain');
                Route::post('/{breach}/notify', 'notify')->name('notify');
                Route::post('/{breach}/subjects', 'subjects')->name('subjects');
                Route::post('/{breach}/close', 'close')->name('close');
            });
        });
    });

    // ---- Channel manager: iCal feeds (v1.2)
    Route::prefix('channels')->name('channels.')->middleware('perm:channels.manage')->controller(Controllers\ChannelController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('/{channel}', 'update')->name('update');
        Route::delete('/{channel}', 'destroy')->name('destroy');
        Route::post('/{channel}/sync', 'sync')->name('sync');
        Route::post('/{channel}/regenerate', 'regenerate')->name('regenerate');
    });

    // ---- Recipes (F&B costing & automatic stock consumption)
    Route::prefix('recipes')->name('recipes.')->middleware('perm:inventory.manage')->controller(Controllers\RecipeController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{menuItem}', 'edit')->name('edit');
        Route::put('/{menuItem}', 'update')->name('update');
    });

    // ---- Cashiering
    // ---- Online payments (v2.1)
    Route::prefix('payments')->name('payments.')->controller(Controllers\OnlinePaymentController::class)->group(function () {
        Route::get('/', 'index')->middleware('perm:payments.view')->name('index');
        Route::post('/', 'store')->middleware('perm:payments.request')->name('store');
        Route::post('/collect', 'collect')->middleware('perm:payments.request')->name('collect');
        Route::get('/payouts', 'payouts')->middleware('perm:payments.settle')->name('payouts');
        Route::post('/payouts', 'storePayout')->middleware('perm:payments.settle')->name('payouts.store');
        Route::post('/payouts/{gpo}/delete', 'destroyPayout')->middleware('perm:payments.settle')->whereNumber('gpo')->name('payouts.destroy');
        Route::get('/settings', 'settings')->middleware('perm:payments.settings')->name('settings');
        Route::post('/settings', 'saveSettings')->middleware('perm:payments.settings')->name('settings.save');
        Route::get('/{olp}', 'show')->middleware('perm:payments.view')->whereNumber('olp')->name('show');
        Route::post('/{olp}/send', 'send')->middleware('perm:payments.request')->whereNumber('olp')->name('send');
        Route::post('/{olp}/cancel', 'cancel')->middleware('perm:payments.request')->whereNumber('olp')->name('cancel');
        Route::post('/{olp}/check', 'check')->middleware('perm:payments.view')->whereNumber('olp')->name('check');
        Route::post('/{olp}/refund', 'refund')->middleware('perm:payments.refund')->whereNumber('olp')->name('refund');
        Route::post('/{olp}/accept', 'accept')->middleware('perm:payments.refund')->whereNumber('olp')->name('accept');
    });
    // ---- Laundry & linen (v2.2)
    // v2.4 store requisitions (indents), issue notes, department budgets & sub-store pars
    Route::prefix('requisitions')->name('req.')->controller(Controllers\RequisitionController::class)->group(function () {
        Route::get('/', 'index')->middleware('perm:requisitions.view')->name('index');
        Route::get('/create', 'create')->middleware('perm:requisitions.raise')->name('create');
        Route::post('/', 'store')->middleware('perm:requisitions.raise')->name('store');
        Route::get('/setup', 'setup')->middleware('perm:requisitions.view')->name('setup');
        Route::put('/setup/departments/{rdept}', 'updateDepartment')->middleware('perm:requisitions.manage')->name('dept.update');
        Route::post('/setup/settings', 'saveSettings')->middleware('perm:requisitions.manage')->name('settings');
        Route::get('/pars', 'pars')->middleware('perm:requisitions.view')->name('pars');
        Route::post('/pars', 'savePars')->middleware('perm:requisitions.manage')->name('pars.save');
        Route::post('/issues/{sissue}/acknowledge', 'acknowledge')->middleware('perm:requisitions.raise')->name('ack');
        Route::get('/issues/{sissue}/print', 'printIssue')->middleware('perm:requisitions.view')->name('issue.print');
        Route::get('/{req}', 'show')->middleware('perm:requisitions.view')->name('show');
        Route::get('/{req}/edit', 'edit')->middleware('perm:requisitions.raise')->name('edit');
        Route::put('/{req}', 'update')->middleware('perm:requisitions.raise')->name('update');
        Route::get('/{req}/print', 'print')->middleware('perm:requisitions.view')->name('print');
        Route::post('/{req}/submit', 'submit')->middleware('perm:requisitions.raise')->name('submit');
        Route::post('/{req}/approve', 'approve')->middleware('perm:requisitions.approve')->name('approve');
        Route::post('/{req}/reject', 'reject')->middleware('perm:requisitions.approve')->name('reject');
        Route::post('/{req}/issue', 'issue')->middleware('perm:requisitions.issue')->name('issue');
        Route::post('/{req}/close', 'close')->middleware('perm:requisitions.issue')->name('close');
        Route::post('/{req}/cancel', 'cancel')->middleware('perm:requisitions.raise')->name('cancel');
        Route::post('/{req}/repeat', 'repeat')->middleware('perm:requisitions.raise')->name('repeat');
        Route::post('/{req}/purchase', 'purchase')->middleware('perm:inventory.manage')->name('purchase');
    });

    // v2.3 fixed assets & preventive maintenance
    Route::prefix('assets')->group(function () {
        Route::middleware('perm:assets.view')->controller(Controllers\AssetController::class)->group(function () {
            Route::get('/', 'index')->name('assets.index');
            Route::get('/export', 'export')->name('assets.export');
            Route::get('/tags', 'tags')->name('assets.tags');
            Route::get('/categories', 'categories')->name('assets.categories');
        });
        Route::middleware('perm:assets.manage')->controller(Controllers\AssetController::class)->group(function () {
            Route::get('/create', 'create')->name('assets.create');
            Route::post('/', 'store')->name('assets.store');
            Route::post('/categories', 'saveCategory')->name('assets.categories.store');
            Route::put('/categories/{acat}', 'saveCategory')->name('assets.categories.update');
            Route::get('/{asset}/edit', 'edit')->name('assets.edit');
            Route::put('/{asset}', 'update')->name('assets.update');
            Route::post('/{asset}/status', 'status')->name('assets.status');
            Route::post('/{asset}/dispose', 'dispose')->name('assets.dispose');
            Route::delete('/{asset}', 'destroy')->name('assets.destroy');
        });
        Route::controller(Controllers\AssetDepreciationController::class)->group(function () {
            Route::get('/depreciation', 'index')->middleware('perm:assets.view')->name('assets.depreciation');
            Route::post('/depreciation', 'run')->middleware('perm:assets.depreciation')->name('assets.depreciation.run');
            Route::get('/depreciation/{deprun}', 'show')->middleware('perm:assets.view')->name('assets.depreciation.show');
            Route::post('/depreciation/{deprun}/reverse', 'reverse')->middleware('perm:assets.depreciation')->name('assets.depreciation.reverse');
        });
        Route::get('/{asset}', [Controllers\AssetController::class, 'show'])->middleware('perm:assets.view')->name('assets.show');
    });
    Route::prefix('pm-schedules')->name('pm.')->middleware('perm:maintenance.manage')->controller(Controllers\PmScheduleController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('/generate', 'generate')->name('generate');
        Route::put('/{pmsched}', 'update')->name('update');
        Route::post('/{pmsched}/toggle', 'toggle')->name('toggle');
        Route::delete('/{pmsched}', 'destroy')->name('destroy');
    });

    Route::prefix('laundry')->name('laundry.')->controller(Controllers\LaundryController::class)->group(function () {
        Route::get('/', 'index')->middleware('perm:laundry.view')->name('index');
        Route::get('/create', 'create')->middleware('perm:laundry.orders')->name('create');
        Route::post('/', 'store')->middleware('perm:laundry.orders')->name('store');
        Route::get('/prices', 'prices')->middleware('perm:laundry.view')->name('prices');
        Route::post('/prices', 'savePrice')->middleware('perm:laundry.manage')->name('prices.save');
        Route::post('/prices/{litem}/toggle', 'togglePrice')->middleware('perm:laundry.manage')->whereNumber('litem')->name('prices.toggle');
        Route::post('/settings', 'saveSettings')->middleware('perm:laundry.manage')->name('settings.save');
        Route::get('/{lorder}', 'show')->middleware('perm:laundry.view')->whereNumber('lorder')->name('show');
        Route::get('/{lorder}/docket', 'docket')->middleware('perm:laundry.view')->whereNumber('lorder')->name('docket');
        Route::post('/{lorder}/items', 'items')->middleware('perm:laundry.orders')->whereNumber('lorder')->name('items');
        Route::post('/{lorder}/ready', 'ready')->middleware('perm:laundry.orders')->whereNumber('lorder')->name('ready');
        Route::post('/{lorder}/deliver', 'deliver')->middleware('perm:laundry.orders')->whereNumber('lorder')->name('deliver');
        Route::post('/{lorder}/cancel', 'cancel')->middleware('perm:laundry.orders')->whereNumber('lorder')->name('cancel');
    });
    Route::prefix('linen')->name('linen.')->controller(Controllers\LinenController::class)->group(function () {
        Route::get('/', 'index')->middleware('perm:laundry.view')->name('index');
        Route::post('/move', 'move')->middleware('perm:laundry.linen')->name('move');
        Route::post('/count', 'count')->middleware('perm:laundry.linen')->name('count');
        Route::post('/types', 'saveType')->middleware('perm:laundry.manage')->name('types.save');
        Route::post('/levels', 'saveLevels')->middleware('perm:laundry.manage')->name('levels.save');
        Route::get('/batches', 'batches')->middleware('perm:laundry.view')->name('batches');
        Route::post('/batches', 'send')->middleware('perm:laundry.linen')->name('send');
        Route::get('/batches/{lbatch}', 'batch')->middleware('perm:laundry.view')->whereNumber('lbatch')->name('batch');
        Route::post('/batches/{lbatch}/receive', 'receive')->middleware('perm:laundry.linen')->whereNumber('lbatch')->name('receive');
        Route::post('/batches/{lbatch}/close', 'close')->middleware('perm:laundry.linen')->whereNumber('lbatch')->name('close');
        Route::post('/batches/{lbatch}/bill', 'bill')->middleware('perm:ap.manage')->whereNumber('lbatch')->name('bill');
    });
    Route::prefix('shifts')->name('shifts.')->middleware('perm:folio.shift')->controller(Controllers\ShiftController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'open')->name('open');
        Route::post('/cash-in', 'cashIn')->name('cash-in');
        Route::post('/cash-out', 'cashOut')->name('cash-out');
        Route::get('/{shift}', 'show')->name('show');
        Route::post('/{shift}/close', 'close')->name('close');
    });

    // ---- Loyalty
    Route::get('/loyalty', [Controllers\LoyaltyController::class, 'index'])->middleware('perm:guests.view')->name('loyalty.index');
    Route::post('/loyalty/adjust/{guest}', [Controllers\LoyaltyController::class, 'adjust'])->middleware('perm:guests.manage')->name('loyalty.adjust');
    Route::post('/folios/{folio}/redeem', [Controllers\LoyaltyController::class, 'redeem'])->middleware('perm:folio.loyalty')->name('folios.redeem');

    // ---- Front office
    Route::prefix('front-desk')->name('frontdesk.')->controller(Controllers\FrontDeskController::class)->group(function () {
        Route::middleware('perm:frontdesk.view')->group(function () {
            Route::get('/rooms', 'rooms')->name('rooms');
            Route::get('/tape-chart', 'tapeChart')->name('tapechart');
            Route::get('/arrivals', 'arrivals')->name('arrivals');
            Route::get('/in-house', 'inHouse')->name('inhouse');
            Route::get('/departures', 'departures')->name('departures');
            Route::get('/stay/{stay}/grc', 'grc')->name('grc');
        });
        Route::controller(Controllers\GrcController::class)->group(function () {
            Route::get('/stay/{stay}/grc/sign', 'sign')->middleware('perm:frontdesk.checkin')->name('grc.sign');
            Route::post('/stay/{stay}/grc/sign', 'saveSignature')->middleware('perm:frontdesk.checkin')->name('grc.signature.store');
            Route::get('/stay/{stay}/grc/signature.png', 'signature')->middleware('perm:frontdesk.view')->name('grc.signature');
        });
        Route::middleware('perm:frontdesk.checkin')->group(function () {
            Route::get('/walk-in', 'walkIn')->name('walkin');
            Route::post('/walk-in', 'storeWalkIn')->name('walkin.store');
            Route::get('/stay/{stay}/check-in', 'checkInForm')->name('checkin');
            Route::post('/stay/{stay}/check-in', 'checkIn')->name('checkin.store');
        });
        Route::middleware('perm:frontdesk.checkout')->group(function () {
            Route::get('/stay/{stay}/check-out', 'checkOutForm')->name('checkout');
            Route::post('/stay/{stay}/check-out', 'checkOut')->name('checkout.store');
        });
        Route::middleware('perm:frontdesk.roommove')->group(function () {
            Route::get('/stay/{stay}/move', 'moveForm')->name('move');
            Route::post('/stay/{stay}/move', 'move')->name('move.store');
            Route::post('/stay/{stay}/amend', 'amend')->name('amend');
            Route::post('/tape-chart/drop', 'tapeDrop')->name('tapechart.drop');
        });
    });

    // ---- Guest requests queue & guest portal links (v1.7)
    Route::prefix('guest-requests')->name('requests.')->controller(Controllers\GuestRequestController::class)->group(function () {
        Route::middleware('perm:requests.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/count', 'count')->name('count');
        });
        Route::middleware('perm:requests.manage')->group(function () {
            Route::post('/', 'store')->name('store');
            Route::get('/stay/{stay}/portal', 'portal')->name('portal');
            Route::post('/stay/{stay}/portal/send', 'sendPortal')->middleware('throttle:10,1')->name('portal.send');
            Route::post('/stay/{stay}/portal/regenerate', 'regenerate')->name('portal.regenerate');
            Route::post('/{greq}/assign', 'assign')->name('assign');
            Route::post('/{greq}/{action}', 'action')->where('action', 'acknowledge|start|done|decline')->name('action');
        });
    });

    // ---- Reservations
    Route::prefix('reservations')->name('reservations.')->controller(Controllers\ReservationController::class)->group(function () {
        Route::get('/', 'index')->middleware('perm:reservations.view')->name('index');
        Route::get('/availability', 'availability')->middleware('perm:reservations.view')->name('availability');
        Route::get('/api/rates', 'apiRates')->middleware('perm:reservations.view')->name('api.rates');
        Route::get('/api/free-rooms', 'apiFreeRooms')->middleware('perm:reservations.view')->name('api.rooms');
        Route::get('/api/guests', 'apiGuests')->middleware('perm:reservations.view')->name('api.guests');
        Route::get('/desk/search', [Controllers\ReservationDeskController::class, 'search'])->middleware('perm:reservations.view')->name('desk.search');
        Route::get('/export', [Controllers\ReservationDeskController::class, 'export'])->middleware('perm:reservations.view')->name('export');
        Route::get('/plan/print', [Controllers\ReservationDeskController::class, 'printPlan'])->middleware('perm:reservations.view')->name('plan');
        Route::get('/day-summary/print', [Controllers\ReservationDeskController::class, 'daySummary'])->middleware('perm:reservations.view')->name('daysummary');
        Route::get('/amenities', [Controllers\ReservationDeskController::class, 'amenities'])->middleware('perm:reservations.view')->name('amenities');
        Route::post('/amenities', [Controllers\ReservationDeskController::class, 'storeAmenity'])->middleware('perm:reservations.edit')->name('amenities.store');
        Route::post('/images', [Controllers\ReservationDeskController::class, 'storeImage'])->middleware('perm:reservations.edit')->name('images.store');
        Route::post('/images/{image}/hide', [Controllers\ReservationDeskController::class, 'destroyImage'])->middleware('perm:reservations.edit')->name('images.hide');
        Route::get('/stays/{stay}/card', [Controllers\ReservationDeskController::class, 'stayCard'])->middleware('perm:reservations.view')->name('stay');
        Route::post('/stays/{stay}/checkout-preview', [Controllers\ReservationDeskController::class, 'previewCheckout'])->middleware('perm:frontdesk.checkout')->name('checkout.preview');
        Route::get('/rooms/{room}/card', [Controllers\ReservationDeskController::class, 'roomCard'])->middleware('perm:reservations.view')->name('room');
        Route::post('/stays/{stay}/dates/preview', [Controllers\ReservationDeskController::class, 'previewDates'])->middleware('perm:reservations.view')->name('dates.preview');
        Route::post('/stays/{stay}/dates', [Controllers\ReservationDeskController::class, 'dates'])->middleware('perm:reservations.edit')->name('dates');
        Route::post('/stays/{stay}/place-check', [Controllers\ReservationDeskController::class, 'placeCheck'])->middleware('perm:reservations.view')->name('place.check');
        Route::post('/stays/{stay}/split/preview', [Controllers\ReservationDeskController::class, 'previewSplit'])->middleware('perm:reservations.view')->name('split.preview');
        Route::post('/stays/{stay}/split', [Controllers\ReservationDeskController::class, 'split'])->middleware('perm:reservations.edit')->name('split');
        Route::post('/stays/{stay}/place', [Controllers\ReservationDeskController::class, 'place'])->middleware('perm:reservations.edit')->name('place');
        Route::post('/outages/preview', [Controllers\ReservationDeskController::class, 'previewOutage'])->middleware('perm:reservations.view')->name('outages.preview');
        Route::post('/outages', [Controllers\ReservationDeskController::class, 'storeOutage'])->middleware('perm:reservations.edit')->name('outages.store');
        Route::post('/outages/{outage}/release', [Controllers\ReservationDeskController::class, 'releaseOutage'])->middleware('perm:reservations.edit')->name('outages.release');
        Route::post('/waitlist', [Controllers\ReservationDeskController::class, 'storeWaitlist'])->middleware('perm:reservations.create')->name('waitlist.store');
        Route::post('/waitlist/{wait}/check', [Controllers\ReservationDeskController::class, 'checkWaitlist'])->middleware('perm:reservations.view')->name('waitlist.check');
        Route::post('/waitlist/{wait}/convert', [Controllers\ReservationDeskController::class, 'convertWaitlist'])->middleware('perm:reservations.create')->name('waitlist.convert');
        Route::get('/desk/tick', [Controllers\ReservationDeskController::class, 'tick'])->middleware('perm:reservations.view')->name('tick');
        Route::post('/images/{image}/primary', [Controllers\ReservationDeskController::class, 'makePrimary'])->middleware('perm:reservations.edit')->name('images.primary');
        Route::post('/waitlist/{wait}/cancel', [Controllers\ReservationDeskController::class, 'cancelWaitlist'])->middleware('perm:reservations.edit')->name('waitlist.cancel');
        Route::post('/desk-views', [Controllers\ReservationDeskController::class, 'saveView'])->middleware('perm:reservations.view')->name('views.store');
        Route::delete('/desk-views/{deskView}', [Controllers\ReservationDeskController::class, 'destroyView'])->middleware('perm:reservations.view')->name('views.destroy');
        Route::get('/create', 'create')->middleware('perm:reservations.create')->name('create');
        Route::post('/', 'store')->middleware('perm:reservations.create')->name('store');
        Route::get('/{reservation}', 'show')->middleware('perm:reservations.view')->name('show');
        Route::get('/{reservation}/print', 'print')->middleware('perm:reservations.view')->name('print');
        Route::get('/{reservation}/edit', 'edit')->middleware('perm:reservations.edit')->name('edit');
        Route::put('/{reservation}', 'update')->middleware('perm:reservations.edit')->name('update');
        Route::post('/{reservation}/cancel', 'cancel')->middleware('perm:reservations.cancel')->name('cancel');
        Route::post('/{reservation}/email', [Controllers\EmailController::class, 'reservation'])->middleware('perm:reservations.view')->name('email');
        Route::post('/{reservation}/checkin-link', [Controllers\EmailController::class, 'precheckin'])->middleware('perm:reservations.edit')->name('checkin-link');
        Route::post('/{reservation}/message', [Controllers\MessageController::class, 'sendForReservation'])->middleware('perm:messages.send')->name('message');
        Route::post('/{reservation}/no-show', 'noShow')->middleware('perm:reservations.cancel')->name('noshow');
        Route::post('/{reservation}/confirm', 'confirm')->middleware('perm:reservations.edit')->name('confirm');
        Route::post('/{reservation}/advance-preview', 'previewAdvance')->middleware('perm:folio.view')->name('advance.preview');
        Route::post('/{reservation}/advance', 'advance')->middleware('perm:folio.payment')->name('advance');
        Route::post('/{reservation}/assign', 'assignRooms')->middleware('perm:reservations.edit')->name('assign');
    });

    // ---- Billing / Folios
    Route::prefix('folios')->name('folios.')->controller(Controllers\FolioController::class)->group(function () {
        Route::get('/', 'index')->middleware('perm:folio.view')->name('index');
        Route::get('/invoices', 'invoices')->middleware('perm:folio.view')->name('invoices');
        Route::get('/non-resident', 'walkIn')->middleware('perm:folio.post')->name('walkin');
        Route::post('/non-resident', 'storeWalkIn')->middleware('perm:folio.post')->name('walkin.store');
        Route::get('/{folio}', 'show')->middleware('perm:folio.view')->name('show');
        Route::get('/{folio}/invoice', 'invoice')->middleware('perm:folio.view')->name('invoice');
        Route::post('/{folio}/email', [Controllers\EmailController::class, 'folio'])->middleware('perm:folio.view')->name('email');
        Route::post('/{folio}/charge-preview', 'previewCharge')->middleware('perm:folio.view')->name('charge.preview');
        Route::post('/{folio}/charge', 'charge')->middleware('perm:folio.post')->name('charge');
        Route::post('/{folio}/payment-preview', 'previewPayment')->middleware('perm:folio.view')->name('payment.preview');
        Route::post('/{folio}/payment', 'payment')->middleware('perm:folio.payment')->name('payment');
        Route::post('/{folio}/discount', 'discount')->middleware('perm:folio.discount')->name('discount');
        Route::post('/{folio}/transfer', 'transfer')->middleware('perm:folio.payment')->name('transfer');
        Route::post('/{folio}/move', 'move')->middleware('perm:folio.split')->name('move');
        Route::post('/{folio}/fixed-charges', 'addFixed')->middleware('perm:folio.post')->name('fixed.store');
        Route::delete('/fixed-charges/{fixedCharge}', 'removeFixed')->middleware('perm:folio.post')->name('fixed.destroy');
        Route::post('/{folio}/close', 'close')->middleware('perm:folio.payment')->name('close');
        Route::post('/{folio}/reopen', 'reopen')->middleware('perm:folio.void')->name('reopen');
        Route::get('/transaction/{tx}/receipt', 'receipt')->middleware('perm:folio.view')->name('receipt');
        Route::post('/transaction/{tx}/void', 'void')->middleware('perm:folio.void')->name('void');
    });

    // ---- v3.0 → v4.3 Ghana GRA VAT compliance: E-VAT stamping, credit notes, cancellations, VAT & Tourism Levy return
    Route::prefix('vat')->name('gst.')->controller(Controllers\GstController::class)->group(function () {
        Route::middleware('perm:gst.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/e-vat', 'einvoices')->name('einvoices');
            Route::get('/credit-notes', 'creditNotes')->name('credit_notes');
            Route::get('/return', 'vatReturn')->name('return');
            Route::get('/return/export', 'exportReturn')->name('return.export');
            Route::get('/documents/{folio}/payload', 'payload')->name('payload');
        });
        Route::middleware('perm:gst.manage')->group(function () {
            Route::post('/return/file', 'file')->name('file');
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'saveSettings')->name('settings.save');
            Route::post('/e-vat/retry', 'retryAll')->name('retry');
            Route::post('/documents/{folio}/e-vat', 'generate')->name('generate');
            Route::post('/restaurant-bills/{order}/e-vat', 'stampPos')->whereNumber('order')->name('pos_stamp'); // v4.4
            Route::post('/restaurant-bills/{order}/buyer', 'posBuyer')->whereNumber('order')->name('pos_buyer');
            Route::post('/documents/{folio}/buyer', 'refreshBuyer')->name('buyer');
            Route::post('/documents/{folio}/cancel', 'cancel')->name('cancel');
        });
        Route::middleware('perm:folio.credit_note')->group(function () {
            Route::get('/documents/{folio}/credit-note', 'creditNoteForm')->name('credit_note.create');
            Route::post('/documents/{folio}/credit-note', 'storeCreditNote')->name('credit_note.store');
        });
    });

    // ---- Guests & CRM
    Route::post('/guests/{guest}/documents', [Controllers\GrcController::class, 'upload'])->middleware('perm:frontdesk.checkin,guests.manage')->name('guests.documents.store');
    Route::get('/guest-documents/{document}', [Controllers\GrcController::class, 'document'])->middleware('perm:guests.view,frontdesk.checkin')->name('guests.documents.show');
    Route::delete('/guest-documents/{document}', [Controllers\GrcController::class, 'destroyDocument'])->middleware('perm:guests.manage')->name('guests.documents.destroy');
    Route::resource('guests', Controllers\GuestController::class)->except(['destroy'])->middleware('perm:guests.view');
    Route::get('/feedback', [Controllers\ReputationController::class, 'responses'])->middleware('perm:feedback.view')->name('feedback.index');

    // ---- v3.2 restaurant table reservations, floor plan & waitlist
    Route::prefix('dining')->name('tb.')->controller(Controllers\TableBookingController::class)->group(function () {
        Route::middleware('perm:tables.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/floor', 'floor')->name('floor');
            Route::get('/bookings/{tres}', 'show')->whereNumber('tres')->name('show');
            Route::get('/availability', 'availability')->name('availability');
            Route::get('/lookup', 'lookup')->name('lookup');
        });
        Route::middleware('perm:tables.book')->group(function () {
            Route::post('/bookings', 'store')->name('store');
            Route::put('/bookings/{tres}', 'update')->name('update');
            Route::post('/bookings/{tres}/confirm', 'confirm')->name('confirm');
            Route::post('/bookings/{tres}/seat', 'seat')->name('seat');
            Route::post('/bookings/{tres}/cancel', 'cancel')->name('cancel');
            Route::post('/bookings/{tres}/no-show', 'noShow')->name('noshow');
            Route::post('/bookings/{tres}/reinstate', 'reinstate')->name('reinstate');
            Route::post('/bookings/{tres}/resend', 'resend')->name('resend');
            Route::post('/bookings/{tres}/notify', 'notify')->name('notify');
            Route::post('/walk-in', 'walkIn')->name('walkin');
            Route::post('/waitlist', 'waitlistStore')->name('waitlist.store');
            Route::post('/tables/{ptable}/clear', 'clear')->name('clear');
        });
        Route::middleware('perm:tables.manage')->group(function () {
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'saveSettings')->name('settings.save');
            Route::post('/floor/layout', 'layout')->name('layout');
            Route::post('/tables/{ptable}/block', 'block')->name('block');
        });
    });

    // ---- v3.3 guest transport & fleet desk
    Route::prefix('transport')->name('tr.')->controller(Controllers\TransportController::class)->group(function () {
        Route::middleware('perm:transport.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/trips', 'trips')->name('trips');
            Route::get('/trips/{trip}', 'show')->whereNumber('trip')->name('show');
            Route::get('/trips/{trip}/sheet', 'sheet')->whereNumber('trip')->name('sheet');
            Route::get('/trips/{trip}/options', 'options')->whereNumber('trip')->name('options');
            Route::get('/fleet', 'fleet')->name('fleet');
            Route::get('/expenses', 'expenses')->name('expenses');
        });
        Route::middleware('perm:transport.book')->group(function () {
            Route::get('/trips/create', 'create')->name('create');
            Route::post('/trips', 'store')->name('store');
            Route::put('/trips/{trip}', 'update')->name('update');
            Route::post('/trips/{trip}/confirm', 'confirm')->name('confirm');
            Route::post('/trips/{trip}/assign', 'assign')->name('assign');
            Route::post('/trips/{trip}/unassign', 'unassign')->name('unassign');
            Route::post('/trips/{trip}/start', 'start')->name('start');
            Route::post('/trips/{trip}/onboard', 'onboard')->name('onboard');
            Route::post('/trips/{trip}/complete', 'complete')->name('complete');
            Route::post('/trips/{trip}/no-show', 'noShow')->name('noshow');
            Route::post('/trips/{trip}/cancel', 'cancel')->name('cancel');
            Route::post('/trips/{trip}/resend', 'resend')->name('resend');
            Route::post('/trips/{trip}/charge', 'charge')->name('charge');
            Route::post('/stays/{stay}/charges', 'chargeStay')->name('stay.charge');
        });
        Route::middleware('perm:transport.drive,transport.book')->group(function () {
            Route::get('/my', 'my')->name('my');
            Route::post('/my/{trip}/{action}', 'myAction')->whereNumber('trip')->where('action', 'start|onboard|complete|noshow')->name('my.action');
            Route::post('/my/expense', 'myFuel')->name('my.expense');
        });
        Route::middleware('perm:transport.manage')->group(function () {
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'saveSettings')->name('settings.save');
            Route::post('/expenses', 'storeExpense')->name('expenses.store');
            Route::delete('/expenses/{vexp}', 'destroyExpense')->name('expenses.destroy');
        });
    });

    // ---- v3.4 foreign guests: Form C (FRRO) & forex desk
    Route::prefix('foreign')->name('fg.')->group(function () {
        Route::controller(Controllers\FormCController::class)->group(function () {
            Route::middleware('perm:formc.view')->group(function () {
                Route::get('/form-c', 'index')->name('formc');
                Route::get('/form-c/export', 'export')->name('formc.export');
                Route::get('/form-c/{formc}', 'show')->whereNumber('formc')->name('formc.show');
                Route::get('/form-c/{formc}/print', 'print')->whereNumber('formc')->name('formc.print');
            });
            Route::middleware('perm:formc.manage')->group(function () {
                Route::put('/form-c/{formc}', 'update')->whereNumber('formc')->name('formc.update');
                Route::post('/form-c/{formc}/submit', 'submit')->whereNumber('formc')->name('formc.submit');
                Route::post('/form-c/{formc}/departure', 'departure')->whereNumber('formc')->name('formc.departure');
                Route::post('/form-c/{formc}/exempt', 'exempt')->whereNumber('formc')->name('formc.exempt');
                Route::post('/form-c/{formc}/reopen', 'reopen')->whereNumber('formc')->name('formc.reopen');
                Route::post('/stays/{stay}/form-c', 'create')->name('formc.create');
                Route::post('/guests/{guest}/nationality', 'nationality')->name('nationality');
            });
            Route::middleware('perm:formc.manage,forex.manage')->group(function () {
                Route::get('/settings', 'settings')->name('settings');
                Route::post('/settings', 'saveSettings')->name('settings.save');
            });
        });
        Route::controller(Controllers\ForexController::class)->group(function () {
            Route::middleware('perm:forex.view')->group(function () {
                Route::get('/forex', 'index')->name('fx');
                Route::get('/forex/{fx}', 'show')->whereNumber('fx')->name('fx.show');
                Route::get('/forex/{fx}/certificate', 'certificate')->whereNumber('fx')->name('fx.certificate');
                Route::get('/rates', 'rates')->name('rates');
                Route::get('/position', 'position')->name('position');
                Route::get('/surrenders/{surrender}', 'showSurrender')->whereNumber('surrender')->name('surrender.show');
            });
            Route::middleware('perm:forex.exchange')->group(function () {
                Route::get('/forex/new', 'create')->name('fx.create');
                Route::get('/forex/quote', 'quote')->name('fx.quote');
                Route::post('/forex', 'store')->name('fx.store');
            });
            Route::middleware('perm:forex.manage')->group(function () {
                Route::post('/forex/{fx}/void', 'void')->whereNumber('fx')->name('fx.void');
                Route::post('/rates', 'saveRates')->name('rates.save');
                Route::post('/rates/copy', 'copyRates')->name('rates.copy');
                Route::post('/surrenders', 'surrender')->name('surrender.store');
            });
        });
    });

    // ---- v3.5 front desk operations: log book, hand-over, wake-up calls, messages & parcels, traces
    Route::prefix('desk')->name('desk.')->controller(Controllers\FrontDeskOpsController::class)->group(function () {
        Route::middleware('perm:logbook.view')->group(function () {
            Route::get('/logbook', 'logbook')->name('logbook');
            Route::get('/logbook/{logentry}', 'showEntry')->whereNumber('logentry')->name('logbook.show');
            Route::post('/logbook/acknowledge', 'acknowledge')->name('logbook.ack');
            Route::get('/handover', 'handover')->name('handover');
            Route::get('/handover/print', 'handoverPrint')->name('handover.print');
        });
        Route::middleware('perm:logbook.write')->group(function () {
            Route::post('/logbook', 'storeEntry')->name('logbook.store');
            Route::post('/logbook/{logentry}/comment', 'comment')->whereNumber('logentry')->name('logbook.comment');
            Route::post('/logbook/{logentry}/resolve', 'resolve')->whereNumber('logentry')->name('logbook.resolve');
            Route::post('/logbook/{logentry}/reopen', 'reopen')->whereNumber('logentry')->name('logbook.reopen');
        });
        Route::middleware('perm:logbook.manage')->group(function () {
            Route::post('/logbook/{logentry}/pin', 'pin')->whereNumber('logentry')->name('logbook.pin');
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'saveSettings')->name('settings.save');
        });
        Route::middleware('perm:desk.wakeup')->group(function () {
            Route::get('/wakeups', 'wakeups')->name('wakeups');
            Route::get('/wakeups/print', 'wakeupsPrint')->name('wakeups.print');
            Route::post('/wakeups', 'storeWakeup')->name('wakeups.store');
            Route::post('/wakeups/{wakeup}/attempt', 'attempt')->whereNumber('wakeup')->name('wakeups.attempt');
            Route::post('/wakeups/{wakeup}/cancel', 'cancelWakeup')->whereNumber('wakeup')->name('wakeups.cancel');
        });
        Route::middleware('perm:desk.messages')->group(function () {
            Route::get('/messages', 'messages')->name('messages');
            Route::post('/messages', 'storeMessage')->name('messages.store');
            Route::get('/messages/{gmsg}/slip', 'slip')->whereNumber('gmsg')->name('messages.slip');
            Route::post('/messages/{gmsg}/notify', 'notify')->whereNumber('gmsg')->name('messages.notify');
            Route::post('/messages/{gmsg}/deliver', 'deliver')->whereNumber('gmsg')->name('messages.deliver');
            Route::post('/messages/{gmsg}/close', 'closeMessage')->whereNumber('gmsg')->name('messages.close');
        });
        Route::middleware('perm:desk.traces')->group(function () {
            Route::get('/traces', 'traces')->name('traces');
            Route::post('/traces', 'storeTrace')->name('traces.store');
            Route::post('/traces/{trace}/done', 'traceDone')->whereNumber('trace')->name('traces.done');
            Route::post('/traces/{trace}/cancel', 'traceCancel')->whereNumber('trace')->name('traces.cancel');
        });
    });

    // ---- v3.6 budgeting, forecasting & budget-vs-actual
    Route::controller(Controllers\BudgetController::class)->name('budgets.')->group(function () {
        Route::middleware('perm:budget.view')->group(function () {
            Route::get('/budgets', 'index')->name('index');
            Route::get('/budgets/{budget}', 'show')->whereNumber('budget')->name('show');
            Route::get('/budgets/{budget}/export', 'export')->whereNumber('budget')->name('export');
            Route::get('/budget-vs-actual', 'bva')->name('bva');
            Route::post('/budget-vs-actual/comments', 'comment')->name('comment');
            Route::delete('/budget-vs-actual/comments/{bcomment}', 'deleteComment')->whereNumber('bcomment')->name('comment.delete');
        });
        Route::middleware('perm:budget.manage')->group(function () {
            Route::post('/budgets', 'store')->name('store');
            Route::post('/budgets/{budget}/grid', 'saveGrid')->whereNumber('budget')->name('grid');
            Route::post('/budgets/{budget}/spread', 'spread')->whereNumber('budget')->name('spread');
            Route::post('/budgets/{budget}/adjust', 'adjust')->whereNumber('budget')->name('adjust');
            Route::post('/budgets/{budget}/stats', 'saveStats')->whereNumber('budget')->name('stats');
            Route::post('/budgets/{budget}/import', 'import')->whereNumber('budget')->name('import');
            Route::post('/budgets/{budget}/submit', 'submit')->whereNumber('budget')->name('submit');
            Route::post('/budgets/{budget}/forecast', 'forecast')->whereNumber('budget')->name('forecast');
            Route::delete('/budgets/{budget}', 'destroy')->whereNumber('budget')->name('destroy');
        });
        Route::middleware('perm:budget.approve')->group(function () {
            Route::post('/budgets/settings', 'saveSettings')->name('settings');
            Route::post('/budgets/{budget}/approve', 'approve')->whereNumber('budget')->name('approve');
            Route::post('/budgets/{budget}/return', 'returnDraft')->whereNumber('budget')->name('return');
            Route::post('/budgets/{budget}/reopen', 'reopen')->whereNumber('budget')->name('reopen');
            Route::post('/budgets/{budget}/activate', 'activate')->whereNumber('budget')->name('activate');
        });
    });

    // ---- v3.7 hotel system interfaces: PABX call accounting, door-lock key cards & master keys, interface hub
    Route::controller(Controllers\CallController::class)->name('calls.')->group(function () {
        Route::middleware('perm:calls.view')->group(function () {
            Route::get('/calls', 'index')->name('index');
            Route::get('/calls/rate', 'rate')->name('rate');
        });
        Route::middleware('perm:calls.manage')->group(function () {
            Route::post('/calls/import', 'import')->name('import');
            Route::post('/calls/manual', 'store')->name('store');
            Route::post('/calls/reprocess', 'reprocess')->name('reprocess');
            Route::post('/calls/{call}/post', 'post')->whereNumber('call')->name('post');
            Route::post('/calls/{call}/waive', 'waive')->whereNumber('call')->name('waive');
            Route::post('/calls/extensions', 'saveExtension')->name('extensions.store');
            Route::post('/calls/extensions/{ext}', 'saveExtension')->whereNumber('ext')->name('extensions.update');
            Route::post('/calls/extensions-sync', 'syncExtensions')->name('extensions.sync');
            Route::post('/calls/tariffs', 'saveTariff')->name('tariffs.store');
            Route::post('/calls/tariffs/{tariff}', 'saveTariff')->whereNumber('tariff')->name('tariffs.update');
        });
        Route::post('/stay/{stay}/phone-cos', 'cos')->whereNumber('stay')->middleware('perm:calls.manage,keys.issue')->name('cos');
    });
    Route::controller(Controllers\KeyCardController::class)->name('keys.')->group(function () {
        Route::get('/key-cards', 'index')->middleware('perm:keys.view')->name('index');
        Route::middleware('perm:keys.issue')->group(function () {
            Route::post('/key-cards/issue', 'issue')->name('issue');
            Route::post('/key-cards/read', 'read')->name('read');
            Route::post('/key-cards/{card}/lost', 'lost')->whereNumber('card')->name('lost');
            Route::post('/key-cards/{card}/return', 'returnCard')->whereNumber('card')->name('return');
        });
        Route::middleware('perm:keys.master')->group(function () {
            Route::post('/master-keys', 'saveKey')->name('master.store');
            Route::post('/master-keys/{mkey}', 'saveKey')->whereNumber('mkey')->name('master.update');
            Route::post('/master-keys/{mkey}/out', 'signOut')->whereNumber('mkey')->name('master.out');
            Route::post('/key-loans/{loan}/in', 'signIn')->whereNumber('loan')->name('master.in');
            Route::post('/key-loans/{loan}/lost', 'lostKey')->whereNumber('loan')->name('master.lost');
        });
    });
    Route::controller(Controllers\InterfaceController::class)->name('interfaces.')->middleware('perm:interfaces.manage')->group(function () {
        Route::get('/interfaces', 'index')->name('index');
        Route::post('/interfaces/settings', 'saveSettings')->name('settings');
        Route::post('/interfaces/token', 'token')->name('token');
        Route::post('/interfaces/commands/{icmd}/retry', 'retry')->whereNumber('icmd')->name('retry');
        Route::post('/interfaces/commands/retry-all', 'retryAll')->name('retry_all');
    });

    // ---- v3.8 gift vouchers & stored-value cards
    Route::controller(Controllers\GiftVoucherController::class)->name('vouchers.')->group(function () {
        Route::middleware('perm:vouchers.view')->group(function () {
            Route::get('/vouchers', 'index')->name('index');
            Route::get('/vouchers/{gvoucher}', 'show')->whereNumber('gvoucher')->name('show');
            Route::get('/vouchers/{gvoucher}/print', 'printCard')->whereNumber('gvoucher')->name('print');
            Route::post('/vouchers/lookup', 'lookup')->middleware('throttle:60,1')->name('lookup');
        });
        Route::middleware('perm:vouchers.sell')->group(function () {
            Route::post('/vouchers/sell', 'sell')->name('sell');
            Route::post('/vouchers/{gvoucher}/reload', 'reload')->whereNumber('gvoucher')->name('reload');
            Route::post('/vouchers/{gvoucher}/email', 'email')->whereNumber('gvoucher')->name('email');
        });
        Route::post('/vouchers/{gvoucher}/redeem', 'redeemFolio')->whereNumber('gvoucher')->middleware('perm:vouchers.redeem')->name('redeem');
        Route::middleware('perm:vouchers.manage')->group(function () {
            Route::post('/vouchers/{gvoucher}/suspend', 'suspend')->whereNumber('gvoucher')->name('suspend');
            Route::post('/vouchers/{gvoucher}/unsuspend', 'unsuspend')->whereNumber('gvoucher')->name('unsuspend');
            Route::post('/vouchers/{gvoucher}/cancel', 'cancel')->whereNumber('gvoucher')->name('cancel');
            Route::post('/vouchers/promo', 'promo')->name('promo');
            Route::post('/vouchers/types', 'saveType')->name('types.store');
            Route::post('/vouchers/types/{gvtype}', 'saveType')->whereNumber('gvtype')->name('types.update');
            Route::post('/vouchers/settings', 'saveSettings')->name('settings');
            Route::post('/vouchers/run', 'runNow')->name('run');
        });
    });

    // ---- v4.0 API & webhooks (developer console)
    Route::controller(Controllers\DeveloperController::class)->name('developers.')->group(function () {
        Route::middleware('perm:api.view,api.manage')->group(function () {
            Route::get('/developers', 'index')->name('index');
            Route::get('/developers/deliveries/{delivery}', 'delivery')->whereNumber('delivery')->name('delivery');
        });
        Route::middleware('perm:api.manage')->group(function () {
            Route::post('/developers/clients', 'storeClient')->name('clients.store');
            Route::post('/developers/clients/{apiclient}', 'updateClient')->whereNumber('apiclient')->name('clients.update');
            Route::post('/developers/clients/{apiclient}/rotate', 'rotateClient')->whereNumber('apiclient')->name('clients.rotate');
            Route::post('/developers/clients/{apiclient}/revoke', 'revokeClient')->whereNumber('apiclient')->name('clients.revoke');
            Route::post('/developers/webhooks', 'storeWebhook')->name('webhooks.store');
            Route::post('/developers/webhooks/{webhook}', 'updateWebhook')->whereNumber('webhook')->name('webhooks.update');
            Route::post('/developers/webhooks/{webhook}/rotate', 'rotateWebhook')->whereNumber('webhook')->name('webhooks.rotate');
            Route::post('/developers/webhooks/{webhook}/toggle', 'toggleWebhook')->whereNumber('webhook')->name('webhooks.toggle');
            Route::post('/developers/webhooks/{webhook}/ping', 'pingWebhook')->whereNumber('webhook')->name('webhooks.ping');
            Route::post('/developers/webhooks/{webhook}/delete', 'deleteWebhook')->whereNumber('webhook')->name('webhooks.delete');
            Route::post('/developers/deliveries/{delivery}/retry', 'retryDelivery')->whereNumber('delivery')->name('deliveries.retry');
            Route::post('/developers/deliver', 'deliverNow')->name('deliver');
            Route::post('/developers/settings', 'settings')->name('settings');
        });
    });

    // ---- v3.9 engineering: utilities & energy
    Route::controller(Controllers\UtilityController::class)->name('utilities.')->group(function () {
        Route::middleware('perm:utilities.view,utilities.record')->group(function () {
            Route::get('/utilities', 'index')->name('index');
            Route::get('/utilities/meters/{umeter}', 'meter')->whereNumber('umeter')->name('meter');
        });
        Route::middleware('perm:utilities.record')->group(function () {
            Route::post('/utilities/readings', 'saveReadings')->name('readings');
            Route::post('/utilities/meters/{umeter}/reading', 'saveReading')->whereNumber('umeter')->name('reading');
            Route::post('/utilities/readings/{mreading}/ack', 'acknowledge')->whereNumber('mreading')->name('ack');
        });
        Route::middleware('perm:utilities.manage')->group(function () {
            Route::post('/utilities/readings/{mreading}/delete', 'deleteReading')->whereNumber('mreading')->name('reading.delete');
            Route::post('/utilities/meters', 'saveMeter')->name('meters.store');
            Route::post('/utilities/meters/{umeter}', 'saveMeter')->whereNumber('umeter')->name('meters.update');
            Route::post('/utilities/bills', 'saveBill')->name('bills.store');
            Route::post('/utilities/bills/{ubill}', 'saveBill')->whereNumber('ubill')->name('bills.update');
            Route::post('/utilities/bills/{ubill}/post', 'postBill')->whereNumber('ubill')->name('bills.post');
            Route::post('/utilities/bills/{ubill}/rate', 'adoptRate')->whereNumber('ubill')->name('bills.rate');
            Route::post('/utilities/settings', 'saveSettings')->name('settings');
        });
    });

    // ---- v3.9 engineering: licences & statutory compliance
    Route::controller(Controllers\ComplianceController::class)->name('compliance.')->group(function () {
        Route::middleware('perm:compliance.view')->group(function () {
            Route::get('/compliance', 'index')->name('index');
            Route::get('/compliance/print', 'printRegister')->name('print');
            Route::get('/compliance/{citem}', 'show')->whereNumber('citem')->name('show');
            Route::get('/compliance/{citem}/document', 'document')->whereNumber('citem')->name('document');
            Route::get('/compliance/events/{cevent}/document', 'eventDocument')->whereNumber('cevent')->name('event.document');
        });
        Route::middleware('perm:compliance.manage')->group(function () {
            Route::post('/compliance', 'store')->name('store');
            Route::post('/compliance/{citem}', 'update')->whereNumber('citem')->name('update');
            Route::post('/compliance/{citem}/apply', 'apply')->whereNumber('citem')->name('apply');
            Route::post('/compliance/{citem}/renew', 'renew')->whereNumber('citem')->name('renew');
            Route::post('/compliance/{citem}/inspect', 'inspect')->whereNumber('citem')->name('inspect');
            Route::post('/compliance/{citem}/upload', 'upload')->whereNumber('citem')->name('upload');
            Route::post('/compliance/{citem}/archive', 'archive')->whereNumber('citem')->name('archive');
            Route::post('/compliance/template', 'template')->name('template');
            Route::post('/compliance/alerts', 'runAlerts')->name('alerts');
            Route::post('/compliance/settings', 'saveSettings')->name('settings');
        });
    });

    // ---- v3.1 guest feedback & online reputation
    Route::prefix('reputation')->name('rep.')->group(function () {
        Route::controller(Controllers\ReputationController::class)->group(function () {
            Route::middleware('perm:feedback.view')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/responses/{feedback}', 'response')->name('responses.show');
                Route::post('/responses/{feedback}/reviewed', 'markReviewed')->name('responses.reviewed');
            });
            Route::middleware('perm:feedback.manage')->group(function () {
                Route::get('/settings', 'settings')->name('settings');
                Route::post('/settings', 'saveSettings')->name('settings.save');
                Route::post('/templates', 'storeTemplate')->name('templates.store');
                Route::put('/templates/{rtpl}', 'updateTemplate')->name('templates.update');
                Route::delete('/templates/{rtpl}', 'destroyTemplate')->name('templates.destroy');
            });
        });
        Route::controller(Controllers\ReviewController::class)->prefix('reviews')->name('reviews.')->group(function () {
            Route::middleware('perm:feedback.view')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/{review}', 'show')->whereNumber('review')->name('show');
            });
            Route::middleware('perm:feedback.manage')->group(function () {
                Route::post('/', 'store')->name('store');
                Route::post('/import', 'import')->name('import');
            Route::post('/pull', 'pull')->name('pull');
            });
            Route::middleware('perm:feedback.respond')->group(function () {
                Route::post('/{review}/draft', 'draft')->name('draft');
                Route::post('/{review}/submit', 'submit')->name('submit');
                Route::post('/{review}/posted', 'posted')->name('posted');
                Route::post('/{review}/ignore', 'ignore')->name('ignore');
                Route::post('/{review}/restore', 'restore')->name('restore');
                Route::post('/{review}/link', 'link')->name('link');
            });
            Route::middleware('perm:feedback.approve')->group(function () {
                Route::post('/{review}/approve', 'approve')->name('approve');
                Route::post('/{review}/return', 'returnDraft')->name('return');
            });
        });
        Route::controller(Controllers\SurveyController::class)->group(function () {
            Route::middleware('perm:feedback.view')->group(function () {
                Route::get('/surveys', 'index')->name('surveys.index');
                Route::get('/surveys/{survey}/preview', 'preview')->name('surveys.preview');
                Route::get('/invitations', 'invitations')->name('invitations');
            });
            Route::middleware('perm:feedback.manage')->group(function () {
                Route::post('/surveys', 'store')->name('surveys.store');
                Route::get('/surveys/{survey}/edit', 'edit')->name('surveys.edit');
                Route::put('/surveys/{survey}', 'update')->name('surveys.update');
                Route::post('/invitations', 'invite')->name('invitations.store');
                Route::post('/invitations/{invitation}/send', 'sendNow')->name('invitations.send');
            });
        });
        Route::controller(Controllers\CaseController::class)->prefix('cases')->name('cases.')->middleware('perm:feedback.cases')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::post('/from-feedback/{feedback}', 'fromFeedback')->name('from_feedback');
            Route::post('/from-review/{review}', 'fromReview')->name('from_review');
            Route::get('/{fcase}', 'show')->whereNumber('fcase')->name('show');
            Route::post('/{fcase}/assign', 'assign')->name('assign');
            Route::post('/{fcase}/start', 'start')->name('start');
            Route::post('/{fcase}/note', 'note')->name('note');
            Route::post('/{fcase}/contact', 'contact')->name('contact');
            Route::post('/{fcase}/resolve', 'resolve')->name('resolve');
            Route::post('/{fcase}/close', 'close')->name('close');
            Route::post('/{fcase}/reopen', 'reopen')->name('reopen');
        });
    });

    // ---- Offline-first workspace and authenticated browser outbox
    Route::prefix('offline')->name('offline.')->controller(Controllers\OfflineSyncController::class)->middleware('throttle:120,1')->group(function () {
        Route::get('/housekeeping', 'workspace')->middleware('perm:housekeeping.view,housekeeping.attendant,housekeeping.manage')->name('housekeeping');
        Route::get('/housekeeping/snapshot', 'housekeepingSnapshot')->middleware('perm:housekeeping.view,housekeeping.attendant,housekeeping.manage')->name('housekeeping.snapshot');
        Route::get('/guest-requests', 'guestRequests')->middleware('perm:requests.view,requests.manage')->name('guest_requests');
        Route::get('/guest-requests/snapshot', 'guestRequestSnapshot')->middleware('perm:requests.view,requests.manage')->name('guest_requests.snapshot');
        Route::post('/sync', 'sync')->middleware('perm:housekeeping.manage,housekeeping.attendant,requests.manage')->name('sync');
        // v7.39 desktop offline mode — multi-day cache-and-sync
        Route::prefix('desktop')->name('desktop.')->controller(Controllers\DesktopSyncController::class)->group(function () {
            Route::get('/', 'workspace')->middleware('perm:frontoffice.view,frontoffice.manage')->name('workspace');
            Route::get('/pack', 'pack')->middleware('perm:frontoffice.view,frontoffice.manage')->name('pack');
            Route::get('/delta', 'delta')->middleware('perm:frontoffice.view,frontoffice.manage')->name('delta');
            Route::post('/sync', 'sync')->middleware('perm:frontoffice.manage')->name('sync');
        });
    });

    // ---- Housekeeping / Maintenance / Lost & Found
    Route::prefix('housekeeping')->name('housekeeping.')->controller(Controllers\HousekeepingController::class)->group(function () {
        Route::get('/', 'index')->middleware('perm:housekeeping.view')->name('index');
        Route::post('/status', 'updateStatus')->middleware('perm:housekeeping.manage')->name('status');
        Route::get('/tasks', 'tasks')->middleware('perm:housekeeping.view')->name('tasks');
        Route::post('/tasks', 'storeTask')->middleware('perm:housekeeping.manage')->name('tasks.store');
        Route::post('/tasks/generate', 'generate')->middleware('perm:housekeeping.manage')->name('tasks.generate');
        Route::post('/tasks/{task}', 'updateTask')->middleware('perm:housekeeping.manage')->name('tasks.update');
    });
    // Room-attendant mobile screen + supervisor inspection
    Route::prefix('hk')->name('hk.')->controller(Controllers\HkMobileController::class)->group(function () {
        Route::get('/my', 'my')->middleware('perm:housekeeping.attendant,housekeeping.manage')->name('my');
        foreach (['start', 'dnd', 'complete', 'minibar', 'issue', 'found'] as $a) {
            Route::post("/tasks/{task}/{$a}", $a)->middleware('perm:housekeeping.attendant,housekeeping.manage')->name($a);
        }
        Route::post('/tasks/{task}/inspect', 'inspect')->middleware('perm:housekeeping.manage')->name('inspect');
    });

    // ---- Spa & activities
    Route::prefix('spa')->name('spa.')->controller(Controllers\SpaController::class)->group(function () {
        Route::get('/', 'index')->middleware('perm:spa.view')->name('index');
        Route::post('/', 'store')->middleware('perm:spa.manage')->name('store');
        Route::put('/{appt}', 'update')->middleware('perm:spa.manage')->name('update');
        Route::post('/{appt}/status', 'status')->middleware('perm:spa.manage')->name('status');
        Route::post('/{appt}/settle', 'settle')->middleware('perm:spa.manage')->name('settle');
    });

    Route::middleware('perm:maintenance.manage')->group(function () {
        Route::get('/maintenance', [Controllers\MaintenanceController::class, 'index'])->name('maintenance.index');
        Route::post('/maintenance', [Controllers\MaintenanceController::class, 'store'])->name('maintenance.store');
        Route::post('/maintenance/{ticket}', [Controllers\MaintenanceController::class, 'update'])->name('maintenance.update');
    });
    // ---- v3.5 lost & found (full lifecycle)
    Route::prefix('lost-found')->name('lostfound.')->controller(Controllers\LostFoundController::class)->middleware('perm:lostfound.manage')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/{item}', 'show')->whereNumber('item')->name('show');
        Route::put('/{item}', 'update')->whereNumber('item')->name('update');
        Route::get('/{item}/tag', 'tag')->whereNumber('item')->name('tag');
        Route::get('/{item}/receipt', 'receipt')->whereNumber('item')->name('receipt');
        Route::post('/{item}/return', 'returnItem')->whereNumber('item')->name('return');
        Route::post('/{item}/dispose', 'dispose')->whereNumber('item')->name('dispose');
        Route::post('/reports', 'report')->name('report');
        Route::get('/reports/{lreport}', 'showReport')->whereNumber('lreport')->name('report.show');
        Route::post('/reports/{lreport}/match/{item}', 'match')->whereNumber('lreport')->whereNumber('item')->name('report.match');
        Route::post('/reports/{lreport}/close', 'closeReport')->whereNumber('lreport')->name('report.close');
    });

    // ---- POS
    Route::prefix('pos')->name('pos.')->controller(Controllers\PosController::class)->group(function () {
        Route::get('/', 'index')->middleware('perm:pos.view')->name('index');
        Route::get('/guests', 'guestSearch')->middleware('perm:pos.order')->name('guests');
        Route::get('/orders', 'orders')->middleware('perm:pos.view')->name('orders');
        Route::post('/orders', 'open')->middleware('perm:pos.order')->name('open');
        Route::get('/orders/{order}', 'terminal')->middleware('perm:pos.view')->name('terminal');
        Route::post('/orders/{order}/kot', 'kot')->middleware('perm:pos.order')->name('kot');
        Route::post('/orders/{order}/fire', 'fireHeld')->middleware('perm:pos.order')->name('fire');
        Route::post('/orders/{order}/items/{line}/cancel', 'cancelItem')->middleware('perm:pos.void')->name('item.cancel');
        Route::post('/orders/{order}/items/{line}/comp', 'compItem')->middleware('perm:pos.settle')->name('item.comp');
        Route::post('/orders/{order}/allergies', 'allergies')->middleware('perm:pos.order')->name('allergies'); // v5.0
        Route::post('/orders/{order}/details', 'updateDetails')->middleware('perm:pos.order')->name('details');
        Route::post('/orders/{order}/move-table', 'moveTable')->middleware('perm:pos.order')->name('move');
        Route::post('/orders/{order}/merge', 'merge')->middleware('perm:pos.settle')->name('merge');
        Route::post('/orders/{order}/split', 'split')->middleware('perm:pos.settle')->name('split');
        Route::post('/orders/{order}/items/{line}/served', 'served')->middleware('perm:pos.order')->name('item.served');
        Route::post('/orders/{order}/bill', 'bill')->middleware('perm:pos.settle')->name('bill');
        Route::post('/orders/{order}/settle', 'settle')->middleware('perm:pos.settle')->name('settle');
        Route::post('/orders/{order}/delivery-status', 'deliveryStatus')->middleware('perm:pos.order')->name('delivery.status');
        Route::post('/orders/{order}/refund', 'refund')->middleware('perm:pos.refund')->name('refund');
        Route::post('/orders/{order}/void', 'void')->middleware('perm:pos.void')->name('void');
        Route::get('/orders/{order}/print', 'printBill')->middleware('perm:pos.view')->name('print');
        Route::get('/orders/{order}/kot/{kot}', 'printKot')->middleware('perm:pos.view,pos.kitchen')->name('printkot');
        Route::post('/orders/{order}/kot/{kot}/print', 'reprintKot')->middleware('perm:pos.order,pos.kitchen')->name('printkot.send');
        Route::get('/printers', [Controllers\KotPrinterController::class, 'index'])->middleware('perm:pos.printers')->name('printers');
        Route::post('/printers', [Controllers\KotPrinterController::class, 'store'])->middleware('perm:pos.printers')->name('printers.store');
        Route::put('/printers/{printer}', [Controllers\KotPrinterController::class, 'update'])->middleware('perm:pos.printers')->name('printers.update');
        Route::delete('/printers/{printer}', [Controllers\KotPrinterController::class, 'destroy'])->middleware('perm:pos.printers')->name('printers.destroy');
        Route::post('/printers/routing', [Controllers\KotPrinterController::class, 'routing'])->middleware('perm:pos.printers')->name('printers.routing');
        Route::get('/printers/{printer}/test', [Controllers\KotPrinterController::class, 'test'])->middleware('perm:pos.printers')->name('printers.test');
        Route::post('/printers/{printer}/test', [Controllers\KotPrinterController::class, 'sendTest'])->middleware('perm:pos.printers')->name('printers.test.send');
        Route::post('/printers/jobs/{job}/retry', [Controllers\KotPrinterController::class, 'retry'])->middleware('perm:pos.printers,pos.kitchen')->name('printers.retry');
        Route::post('/orders/{order}/accept', 'acceptGuest')->middleware('perm:pos.order')->name('accept');
        Route::post('/orders/{order}/reject', 'rejectGuest')->middleware('perm:pos.void')->name('reject');
        Route::get('/qr-codes', 'qrSheet')->middleware('perm:pos.view')->name('qr');
        Route::post('/qr-codes/{table}/regenerate', 'qrRegenerate')->middleware('perm:masters.manage')->name('qr.regenerate');
        Route::get('/modifiers', [Controllers\PosModifierController::class, 'index'])->middleware('perm:masters.manage')->name('modifiers');
        Route::post('/modifiers/groups', [Controllers\PosModifierController::class, 'storeGroup'])->middleware('perm:masters.manage')->name('modifiers.groups.store');
        Route::put('/modifiers/groups/{group}', [Controllers\PosModifierController::class, 'updateGroup'])->middleware('perm:masters.manage')->name('modifiers.groups.update');
        Route::delete('/modifiers/groups/{group}', [Controllers\PosModifierController::class, 'destroyGroup'])->middleware('perm:masters.manage')->name('modifiers.groups.destroy');
        Route::post('/modifiers', [Controllers\PosModifierController::class, 'storeModifier'])->middleware('perm:masters.manage')->name('modifiers.store');
        Route::put('/modifiers/{modifier}', [Controllers\PosModifierController::class, 'updateModifier'])->middleware('perm:masters.manage')->name('modifiers.update');
        Route::delete('/modifiers/{modifier}', [Controllers\PosModifierController::class, 'destroyModifier'])->middleware('perm:masters.manage')->name('modifiers.destroy');
        // {item} is globally bound to LostFoundItem; use a unique key for MenuItem model binding here.
        Route::post('/modifiers/items/{menuItem}/groups', [Controllers\PosModifierController::class, 'assign'])->middleware('perm:masters.manage')->name('modifiers.assign');
    });

    // ---- Banquet
    // v2.6 guest marketing & CRM
    Route::prefix('crm')->name('crm.')->group(function () {
        Route::get('/', [Controllers\CrmController::class, 'index'])->middleware('perm:crm.view')->name('index');
        Route::get('/settings', [Controllers\CrmController::class, 'settings'])->middleware('perm:crm.send')->name('settings');
        Route::post('/settings', [Controllers\CrmController::class, 'saveSettings'])->middleware('perm:crm.send')->name('settings.save');
        Route::post('/refresh-stats', [Controllers\CrmController::class, 'refreshStats'])->middleware('perm:crm.manage')->name('refresh');
        Route::post('/segments/preview', [Controllers\SegmentController::class, 'preview'])->middleware('perm:crm.view')->name('segments.preview');
        Route::get('/segments/{segment}/export', [Controllers\SegmentController::class, 'export'])->middleware('perm:crm.manage')->name('segments.export');
        Route::resource('segments', Controllers\SegmentController::class)->middleware('perm:crm.view');
        Route::prefix('campaigns')->name('campaigns.')->controller(Controllers\CampaignController::class)->group(function () {
            Route::get('/', 'index')->middleware('perm:crm.view')->name('index');
            Route::get('/create', 'create')->middleware('perm:crm.manage')->name('create');
            Route::post('/', 'store')->middleware('perm:crm.manage')->name('store');
            Route::get('/{campaign}', 'show')->middleware('perm:crm.view')->name('show');
            Route::get('/{campaign}/edit', 'edit')->middleware('perm:crm.manage')->name('edit');
            Route::put('/{campaign}', 'update')->middleware('perm:crm.manage')->name('update');
            Route::post('/{campaign}/test', 'test')->middleware('perm:crm.manage', 'throttle:10,1')->name('test');
            Route::post('/{campaign}/duplicate', 'duplicate')->middleware('perm:crm.manage')->name('duplicate');
            Route::post('/{campaign}/send', 'send')->middleware('perm:crm.send')->name('send');
            Route::post('/{campaign}/schedule', 'schedule')->middleware('perm:crm.send')->name('schedule');
            Route::post('/{campaign}/unschedule', 'unschedule')->middleware('perm:crm.send')->name('unschedule');
            Route::post('/{campaign}/cancel', 'cancel')->middleware('perm:crm.manage')->name('cancel');
        });
        Route::prefix('automations')->name('journeys.')->controller(Controllers\JourneyController::class)->group(function () {
            Route::get('/', 'index')->middleware('perm:crm.view')->name('index');
            Route::get('/create', 'create')->middleware('perm:crm.manage')->name('create');
            Route::post('/', 'store')->middleware('perm:crm.manage')->name('store');
            Route::get('/{journey}', 'show')->middleware('perm:crm.view')->name('show');
            Route::get('/{journey}/edit', 'edit')->middleware('perm:crm.manage')->name('edit');
            Route::put('/{journey}', 'update')->middleware('perm:crm.manage')->name('update');
            Route::post('/{journey}/toggle', 'toggle')->middleware('perm:crm.send')->name('toggle');
            Route::post('/{journey}/run', 'run')->middleware('perm:crm.send')->name('run');
            Route::post('/{journey}/test', 'test')->middleware('perm:crm.manage', 'throttle:10,1')->name('test');
        });
        Route::prefix('promo-codes')->name('promos.')->controller(Controllers\PromoCodeController::class)->group(function () {
            Route::get('/', 'index')->middleware('perm:crm.view')->name('index');
            Route::get('/create', 'create')->middleware('perm:crm.manage')->name('create');
            Route::post('/', 'store')->middleware('perm:crm.manage')->name('store');
            Route::get('/{promo}/edit', 'edit')->middleware('perm:crm.manage')->name('edit');
            Route::put('/{promo}', 'update')->middleware('perm:crm.manage')->name('update');
            Route::post('/{promo}/toggle', 'toggle')->middleware('perm:crm.manage')->name('toggle');
        });
    });
    // v2.7: corporate & travel-agent rate contracts (allotments, blackouts, production) + credit control
    Route::prefix('contracts')->name('contracts.')->controller(Controllers\ContractController::class)->group(function () {
        Route::get('/', 'index')->middleware('perm:contracts.view')->name('index');
        Route::get('/create', 'create')->middleware('perm:contracts.manage')->name('create');
        Route::post('/', 'store')->middleware('perm:contracts.manage')->name('store');
        Route::get('/{contract}', 'show')->middleware('perm:contracts.view')->name('show');
        Route::get('/{contract}/edit', 'edit')->middleware('perm:contracts.manage')->name('edit');
        Route::put('/{contract}', 'update')->middleware('perm:contracts.manage')->name('update');
        Route::delete('/{contract}', 'destroy')->middleware('perm:contracts.manage')->name('destroy');
        Route::get('/{contract}/letter', 'letter')->middleware('perm:contracts.view')->name('letter');
        Route::post('/{contract}/activate', 'activate')->middleware('perm:contracts.approve')->name('activate');
        Route::post('/{contract}/suspend', 'suspend')->middleware('perm:contracts.approve')->name('suspend');
        Route::post('/{contract}/renew', 'renew')->middleware('perm:contracts.manage')->name('renew');
    });
    Route::prefix('credit')->name('credit.')->controller(Controllers\CreditController::class)->group(function () {
        Route::get('/', 'index')->middleware('perm:credit.view')->name('index');
        Route::post('/review', 'review')->middleware('perm:credit.manage')->name('review');
        Route::get('/{company}', 'show')->middleware('perm:credit.view')->name('show');
        Route::post('/{company}/hold', 'hold')->middleware('perm:credit.manage')->name('hold');
        Route::post('/{company}/release', 'release')->middleware('perm:credit.manage')->name('release');
        Route::post('/{company}/terms', 'terms')->middleware('perm:credit.manage')->name('terms');
        Route::post('/{company}/statement', 'statement')->middleware('perm:credit.manage', 'throttle:10,1')->name('statement');
    });
    Route::post('/guests/{guest}/consent', [Controllers\GuestController::class, 'consent'])->middleware('perm:guests.manage,crm.manage')->name('guests.consent');

    Route::prefix('banquet')->name('banquet.')->controller(Controllers\BanquetController::class)->group(function () {
        Route::get('/', 'index')->middleware('perm:banquet.view')->name('index');
        Route::get('/calendar', 'calendar')->middleware('perm:banquet.view')->name('calendar');
        Route::get('/diary', 'diary')->middleware('perm:banquet.view')->name('diary');
        Route::get('/function-sheet', 'sheet')->middleware('perm:banquet.view')->name('sheet');
        Route::get('/settings', 'settings')->middleware('perm:banquet.setup')->name('settings');
        Route::post('/settings', 'saveSettings')->middleware('perm:banquet.setup')->name('settings.save');
        Route::get('/create', 'create')->middleware('perm:banquet.manage')->name('create');
        Route::post('/', 'store')->middleware('perm:banquet.manage')->name('store');
        Route::get('/{event}', 'show')->middleware('perm:banquet.view')->name('show');
        Route::get('/{event}/edit', 'edit')->middleware('perm:banquet.manage')->name('edit');
        Route::put('/{event}', 'update')->middleware('perm:banquet.manage')->name('update');
        Route::post('/{event}/tentative', 'tentative')->middleware('perm:banquet.manage')->name('tentative');
        Route::post('/{event}/confirm', 'confirm')->middleware('perm:banquet.manage')->name('confirm');
        Route::post('/{event}/lost', 'lost')->middleware('perm:banquet.manage')->name('lost');
        Route::post('/{event}/reopen', 'reopen')->middleware('perm:banquet.manage')->name('reopen');
        Route::post('/{event}/cancel', 'cancel')->middleware('perm:banquet.manage')->name('cancel');
        Route::post('/{event}/actuals', 'actuals')->middleware('perm:banquet.manage')->name('actuals');
        Route::post('/{event}/deposit', 'deposit')->middleware('perm:banquet.billing')->name('deposit');
        Route::post('/{event}/refund', 'refund')->middleware('perm:banquet.billing')->name('refund');
        Route::post('/{event}/bill', 'bill')->middleware('perm:banquet.billing')->name('bill');
        Route::get('/{event}/print', 'print')->middleware('perm:banquet.view')->name('print');
        Route::get('/{event}/quote', 'quote')->middleware('perm:banquet.view')->name('quote');
    });

    // ---- Inventory & purchasing
    Route::prefix('inventory')->group(function () {
        Route::get('/', [Controllers\InventoryController::class, 'index'])->middleware('perm:inventory.view')->name('inventory.index');
        Route::get('/ledger', [Controllers\InventoryController::class, 'ledger'])->middleware('perm:inventory.view')->name('inventory.ledger');
        Route::get('/movement', [Controllers\InventoryController::class, 'movement'])->middleware('perm:inventory.manage')->name('inventory.movement');
        Route::post('/movement', [Controllers\InventoryController::class, 'storeMovement'])->middleware('perm:inventory.manage')->name('inventory.movement.store');
        Route::controller(Controllers\StockTakeController::class)->group(function () {
            Route::get('/stock-takes', 'index')->middleware('perm:inventory.view')->name('stocktakes.index');
            Route::post('/stock-takes', 'store')->middleware('perm:inventory.manage')->name('stocktakes.store');
            Route::get('/stock-takes/{stocktake}', 'show')->middleware('perm:inventory.view')->name('stocktakes.show');
            Route::put('/stock-takes/{stocktake}', 'update')->middleware('perm:inventory.manage')->name('stocktakes.update');
            Route::post('/stock-takes/{stocktake}/cancel', 'cancel')->middleware('perm:inventory.manage')->name('stocktakes.cancel');
            Route::get('/stock-takes/{stocktake}/print', 'print')->middleware('perm:inventory.view')->name('stocktakes.print');
            Route::get('/reorder', 'reorder')->middleware('perm:inventory.view')->name('inventory.reorder');
            Route::post('/reorder', 'reorderStore')->middleware('perm:inventory.manage')->name('inventory.reorder.store');
        });
        Route::get('/purchases', [Controllers\PurchaseController::class, 'index'])->middleware('perm:inventory.view')->name('purchases.index');
        Route::get('/purchases/create', [Controllers\PurchaseController::class, 'create'])->middleware('perm:inventory.manage')->name('purchases.create');
        Route::post('/purchases', [Controllers\PurchaseController::class, 'store'])->middleware('perm:inventory.manage')->name('purchases.store');
        Route::get('/purchases/{po}', [Controllers\PurchaseController::class, 'show'])->middleware('perm:inventory.view')->name('purchases.show');
        Route::post('/purchases/{po}/status', [Controllers\PurchaseController::class, 'status'])->middleware('perm:inventory.manage,purchasing.approve,purchasing.approve_high')->name('purchases.status');
        // v4.8 Procurement II – partial goods receipts, short-close, GRN print
        Route::post('/purchases/{po}/receive', [Controllers\PurchaseController::class, 'receive'])->middleware('perm:inventory.manage')->name('purchases.receive');
        Route::post('/purchases/{po}/close', [Controllers\PurchaseController::class, 'close'])->middleware('perm:inventory.manage')->name('purchases.close');
        Route::get('/goods-receipts/{grn}/print', [Controllers\PurchaseController::class, 'grn'])->middleware('perm:inventory.view')->name('grn.print');
        Route::get('/goods-receipts', [Controllers\ProcurementController::class, 'grns'])->middleware('perm:inventory.view')->name('grn.index');
        Route::get('/supplier-scorecard', [Controllers\ProcurementController::class, 'scorecard'])->middleware('perm:inventory.view')->name('proc.scorecard');
        Route::post('/purchasing/settings', [Controllers\ProcurementController::class, 'settings'])->middleware('perm:settings.manage')->name('proc.settings');
        Route::get('/supplier-prices', [Controllers\ProcurementController::class, 'prices'])->middleware('perm:inventory.view')->name('prices.index');
        Route::post('/supplier-prices', [Controllers\ProcurementController::class, 'savePrice'])->middleware('perm:purchasing.prices')->name('prices.store');
        Route::post('/supplier-prices/{sprice}', [Controllers\ProcurementController::class, 'savePrice'])->middleware('perm:purchasing.prices')->name('prices.update');
        Route::post('/supplier-prices/{sprice}/end', [Controllers\ProcurementController::class, 'expirePrice'])->middleware('perm:purchasing.prices')->name('prices.end');
        Route::prefix('rfqs')->name('rfq.')->group(function () {
            Route::get('/', [Controllers\ProcurementController::class, 'rfqs'])->middleware('perm:inventory.view')->name('index');
            Route::get('/create', [Controllers\ProcurementController::class, 'create'])->middleware('perm:purchasing.rfq')->name('create');
            Route::post('/', [Controllers\ProcurementController::class, 'store'])->middleware('perm:purchasing.rfq')->name('store');
            Route::get('/{rfq}', [Controllers\ProcurementController::class, 'show'])->middleware('perm:inventory.view')->name('show');
            Route::get('/{rfq}/edit', [Controllers\ProcurementController::class, 'edit'])->middleware('perm:purchasing.rfq')->name('edit');
            Route::post('/{rfq}/update', [Controllers\ProcurementController::class, 'update'])->middleware('perm:purchasing.rfq')->name('update');
            Route::post('/{rfq}/send', [Controllers\ProcurementController::class, 'send'])->middleware('perm:purchasing.rfq')->name('send');
            Route::post('/{rfq}/suppliers', [Controllers\ProcurementController::class, 'addSupplier'])->middleware('perm:purchasing.rfq')->name('supplier');
            Route::post('/{rfq}/invites/{invite}/resend', [Controllers\ProcurementController::class, 'resend'])->middleware('perm:purchasing.rfq')->name('resend');
            Route::get('/{rfq}/invites/{invite}/quote', [Controllers\ProcurementController::class, 'quoteForm'])->middleware('perm:purchasing.rfq')->name('quote');
            Route::post('/{rfq}/invites/{invite}/quote', [Controllers\ProcurementController::class, 'saveQuote'])->middleware('perm:purchasing.rfq')->name('quote.save');
            Route::post('/{rfq}/close', [Controllers\ProcurementController::class, 'close'])->middleware('perm:purchasing.rfq')->name('close');
            Route::post('/{rfq}/cancel', [Controllers\ProcurementController::class, 'cancel'])->middleware('perm:purchasing.rfq')->name('cancel');
            Route::post('/{rfq}/award', [Controllers\ProcurementController::class, 'award'])->middleware('perm:purchasing.rfq')->name('award');
            Route::get('/{rfq}/print', [Controllers\ProcurementController::class, 'printRfq'])->middleware('perm:inventory.view')->name('print');
            Route::get('/{rfq}/comparative', [Controllers\ProcurementController::class, 'printComparative'])->middleware('perm:inventory.view')->name('comparative');
        });
    });

    // ---- Accounts
    Route::prefix('accounts')->name('accounts.')->controller(Controllers\AccountsController::class)->middleware('perm:accounts.view')->group(function () {
        Route::get('/vouchers', 'vouchers')->name('vouchers');
        Route::post('/vouchers', 'storeVoucher')->middleware('perm:accounts.manage')->name('vouchers.store');
        Route::delete('/vouchers/{voucher}', 'deleteVoucher')->middleware('perm:accounts.manage')->name('vouchers.delete');
        Route::get('/city-ledger', 'cityLedger')->name('cityledger');
        Route::get('/city-ledger/{company}', 'companyLedger')->name('company');
        Route::post('/city-ledger/{company}/receipt', 'companyReceipt')->middleware('perm:accounts.manage')->name('company.receipt');
        Route::get('/cash-book', 'cashBook')->name('cashbook');
        Route::get('/profit-loss', 'pnl')->name('pnl');
        Route::get('/commissions', [Controllers\CommissionController::class, 'index'])->name('commissions');
        Route::post('/commissions/pay', [Controllers\CommissionController::class, 'pay'])->middleware('perm:accounts.manage')->name('commissions.pay');
        Route::post('/commissions/{commission}/void', [Controllers\CommissionController::class, 'void'])->middleware('perm:accounts.manage')->name('commissions.void');
    });

    // ---- HR
    Route::get('/hr/attendance', [Controllers\HrController::class, 'attendance'])->middleware('perm:hr.manage')->name('hr.attendance');
    Route::post('/hr/attendance', [Controllers\HrController::class, 'saveAttendance'])->middleware('perm:hr.manage')->name('hr.attendance.save');
    Route::get('/hr/register', [Controllers\HrController::class, 'register'])->middleware('perm:hr.manage')->name('hr.register');
    // v7.40 ESS review desk
    Route::controller(Controllers\EssController::class)->middleware('perm:hr.manage')->group(function () {
        Route::get('/hr/ess', 'desk')->name('hr.ess');
        Route::post('/hr/ess/requests/{req}', 'decide')->whereNumber('req')->name('hr.ess.decide');
        Route::post('/hr/ess/documents/{doc}', 'reviewDoc')->whereNumber('doc')->name('hr.ess.doc');
        Route::post('/hr/ess/announce', 'announce')->name('hr.ess.announce');
    });

    // v2.0 leave & payroll
    Route::prefix('hr/leave')->name('leave.')->controller(Controllers\LeaveController::class)->middleware('perm:hr.leave')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->middleware('throttle:60,1')->name('store');
        Route::post('/{lreq}/approve', 'approve')->whereNumber('lreq')->name('approve');
        Route::post('/{lreq}/reject', 'reject')->whereNumber('lreq')->name('reject');
        Route::post('/{lreq}/cancel', 'cancel')->whereNumber('lreq')->name('cancel');
    });
    Route::prefix('payroll')->name('payroll.')->controller(Controllers\PayrollController::class)->middleware('perm:payroll.view')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->middleware(['perm:payroll.manage', 'throttle:20,1'])->name('store');
        Route::get('/runs/{prun}', 'show')->whereNumber('prun')->name('show');
        Route::post('/runs/{prun}/recalculate', 'recalc')->whereNumber('prun')->middleware('perm:payroll.manage')->name('recalc');
        Route::post('/runs/{prun}/approve', 'approve')->whereNumber('prun')->middleware('perm:payroll.approve')->name('approve');
        Route::post('/runs/{prun}/reopen', 'reopen')->whereNumber('prun')->middleware('perm:payroll.approve')->name('reopen');
        Route::post('/runs/{prun}/pay', 'pay')->whereNumber('prun')->middleware('perm:payroll.approve')->name('pay');
        Route::delete('/runs/{prun}', 'destroy')->whereNumber('prun')->middleware('perm:payroll.manage')->name('destroy');
        Route::get('/runs/{prun}/bank-advice', 'bankAdvice')->whereNumber('prun')->name('bankadvice');
        Route::get('/runs/{prun}/payslips', 'printAll')->whereNumber('prun')->name('print');
        Route::get('/payslips/{slip}', 'payslip')->whereNumber('slip')->name('payslip');
        Route::post('/payslips/{slip}/lines', 'addLine')->whereNumber('slip')->middleware('perm:payroll.manage')->name('lines.add');
        Route::delete('/lines/{pline}', 'removeLine')->whereNumber('pline')->middleware('perm:payroll.manage')->name('lines.remove');
        Route::get('/components', 'components')->middleware('perm:payroll.manage')->name('components');
        Route::post('/components', 'saveComponent')->middleware('perm:payroll.manage')->name('components.store');
        Route::post('/components/{pcomp}', 'saveComponent')->whereNumber('pcomp')->middleware('perm:payroll.manage')->name('components.update');
        Route::post('/settings', 'saveSettings')->middleware('perm:payroll.manage')->name('settings');
        Route::get('/employees/{employee}', 'employee')->whereNumber('employee')->middleware('perm:payroll.manage')->name('employee');
        Route::post('/employees/{employee}', 'saveEmployee')->whereNumber('employee')->middleware('perm:payroll.manage')->name('employee.save');
        Route::get('/advances', 'advances')->name('advances');
        Route::post('/advances', 'storeAdvance')->middleware(['perm:payroll.manage', 'throttle:30,1'])->name('advances.store');
        Route::post('/advances/{sadv}/cancel', 'cancelAdvance')->whereNumber('sadv')->middleware('perm:payroll.manage')->name('advances.cancel');
        // v4.5 Ghana statutory returns: SSNIT Tier 1, Tier 2, PAYE
        Route::get('/statutory', [Controllers\StatutoryController::class, 'index'])->name('statutory');
        Route::get('/statutory/{kind}/export', [Controllers\StatutoryController::class, 'export'])->where('kind', 'ssnit|tier2|paye')->name('statutory.export');
        Route::post('/statutory/remit', [Controllers\StatutoryController::class, 'remit'])->middleware(['perm:payroll.approve', 'throttle:30,1'])->name('statutory.remit');
        Route::post('/statutory/remittances/{srem}/cancel', [Controllers\StatutoryController::class, 'cancel'])->whereNumber('srem')->middleware('perm:payroll.approve')->name('statutory.cancel');
        Route::post('/statutory/settings', [Controllers\StatutoryController::class, 'settings'])->middleware('perm:payroll.manage')->name('statutory.settings');
    });
    Route::prefix('me')->name('me.')->controller(Controllers\SelfServiceController::class)->middleware('perm:hr.self')->group(function () {
        Route::get('/hr', 'index')->name('hr');
        Route::post('/leave', 'apply')->middleware('throttle:20,1')->name('leave');
        Route::post('/leave/{lreq}/cancel', 'cancel')->whereNumber('lreq')->name('leave.cancel');
        Route::get('/payslips/{slip}', 'payslip')->whereNumber('slip')->name('payslip');
        Route::get('/roster', 'roster')->name('roster'); // v4.9
        Route::post('/clock', 'clock')->middleware('throttle:10,1')->name('clock');
        Route::post('/swaps', 'requestSwap')->middleware('throttle:20,1')->name('swap');
        Route::post('/swaps/{swap}/respond', 'respondSwap')->whereNumber('swap')->name('swap.respond');
        Route::post('/swaps/{swap}/cancel', 'cancelSwap')->whereNumber('swap')->name('swap.cancel');
        Route::post('/pin', 'pin')->middleware('throttle:5,1')->name('pin');
    });
    // v7.40 employee self-service: profile change requests, documents, announcements
    Route::prefix('me')->name('me.')->controller(Controllers\EssController::class)->middleware('perm:hr.self')->group(function () {
        Route::get('/profile', 'profile')->name('profile');
        Route::post('/profile', 'profileUpdate')->middleware('throttle:20,1')->name('profile.update');
        Route::get('/documents', 'documents')->name('documents');
        Route::post('/documents', 'documentUpload')->middleware('throttle:20,1')->name('documents.upload');
        Route::get('/announcements', 'announcements')->name('announcements');
    });

    // v4.9 duty roster & time clock
    Route::prefix('hr/roster')->name('roster.')->controller(Controllers\WorkforceController::class)->middleware('perm:roster.view')->group(function () {
        Route::get('/', 'roster')->name('index');
        Route::get('/print', 'printRoster')->name('print');
        Route::middleware('perm:roster.manage')->group(function () {
            Route::post('/', 'saveRoster')->name('save');
            Route::post('/copy', 'copyRoster')->name('copy');
            Route::post('/publish', 'publishRoster')->name('publish');
            Route::post('/templates', 'saveTemplate')->name('template');
            Route::post('/templates/{stpl}/toggle', 'toggleTemplate')->whereNumber('stpl')->name('template.toggle');
            Route::post('/swaps/{swap}', 'decideSwap')->whereNumber('swap')->name('swap');
            Route::post('/settings', 'rosterSettings')->name('settings');
        });
    });
    Route::prefix('hr/time-clock')->name('clock.')->controller(Controllers\WorkforceController::class)->group(function () {
        Route::get('/kiosk', 'kiosk')->middleware('perm:timeclock.kiosk')->name('kiosk');
        Route::post('/kiosk', 'kioskPunch')->middleware(['perm:timeclock.kiosk', 'throttle:30,1'])->name('kiosk.punch');
        Route::get('/', 'clock')->middleware('perm:roster.view')->name('index');
        Route::middleware('perm:timeclock.approve')->group(function () {
            Route::post('/punches', 'savePunch')->name('punch');
            Route::post('/punches/{punch}', 'updatePunch')->whereNumber('punch')->name('punch.update');
            Route::post('/punches/{punch}/delete', 'deletePunch')->whereNumber('punch')->name('punch.delete');
            Route::post('/approve', 'approveDay')->name('approve');
            Route::post('/pin/{employee}', 'setPin')->whereNumber('employee')->name('pin');
            Route::post('/settings', 'clockSettings')->name('settings');
        });
    });
    // v4.9 service charge & tips (tronc)
    Route::prefix('accounts/service-charge')->name('sc.')->controller(Controllers\ServiceChargeController::class)->middleware('perm:servicecharge.manage,servicecharge.approve')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{scdist}', 'show')->whereNumber('scdist')->name('show');
        Route::get('/{scdist}/print', 'print')->whereNumber('scdist')->name('print');
        Route::post('/{scdist}/cancel', 'cancel')->whereNumber('scdist')->name('cancel');
        Route::middleware('perm:servicecharge.manage')->group(function () {
            Route::post('/tips', 'storeTip')->name('tip');
            Route::post('/tips/{tip}/cancel', 'cancelTip')->whereNumber('tip')->name('tip.cancel');
            Route::post('/', 'store')->name('store');
            Route::post('/{scdist}/recompute', 'recompute')->whereNumber('scdist')->name('recompute');
            Route::post('/{scdist}/lines/{scline}', 'exclude')->whereNumber('scdist')->whereNumber('scline')->name('exclude');
            Route::post('/points', 'points')->name('points');
        });
        Route::middleware('perm:servicecharge.approve')->group(function () {
            Route::post('/{scdist}/approve', 'approve')->whereNumber('scdist')->name('approve');
            Route::post('/{scdist}/post', 'post')->whereNumber('scdist')->name('post');
            Route::post('/settings', 'settings')->name('settings');
        });
    });

    // ---- Night audit
    Route::middleware('perm:nightaudit.run')->group(function () {
        Route::get('/night-audit', [Controllers\NightAuditController::class, 'index'])->name('nightaudit.index');
        Route::post('/night-audit', [Controllers\NightAuditController::class, 'run'])->name('nightaudit.run');
        Route::get('/night-audit/{audit}', [Controllers\NightAuditController::class, 'show'])->name('nightaudit.show');
    });

    // ---- Reports
    // v1.8 accounts payable
    // v4.6 bank & Mobile Money reconciliation
    Route::prefix('accounts/bank-reconciliation')->name('bankrec.')->controller(Controllers\BankRecController::class)->middleware('perm:bankrec.view')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/new', 'create')->middleware('perm:bankrec.manage')->name('create');
        Route::post('/', 'store')->middleware(['perm:bankrec.manage', 'throttle:20,1'])->name('store');
        Route::post('/settings', 'settings')->middleware('perm:bankrec.approve')->name('settings');
        Route::get('/{bstmt}', 'show')->whereNumber('bstmt')->name('show');
        Route::get('/{bstmt}/print', 'print')->whereNumber('bstmt')->name('print');
        Route::post('/{bstmt}/auto-match', 'autoMatch')->whereNumber('bstmt')->middleware('perm:bankrec.manage')->name('automatch');
        Route::post('/{bstmt}/book-fees', 'bookFees')->whereNumber('bstmt')->middleware('perm:bankrec.manage')->name('bookfees');
        Route::post('/{bstmt}/match', 'match')->whereNumber('bstmt')->middleware('perm:bankrec.manage')->name('match');
        Route::post('/{bstmt}/lines', 'addLine')->whereNumber('bstmt')->middleware('perm:bankrec.manage')->name('lines.add');
        Route::post('/{bstmt}/closing', 'closing')->whereNumber('bstmt')->middleware('perm:bankrec.manage')->name('closing');
        Route::post('/{bstmt}/finalise', 'finalise')->whereNumber('bstmt')->middleware('perm:bankrec.approve')->name('finalise');
        Route::post('/{bstmt}/reopen', 'reopen')->whereNumber('bstmt')->middleware('perm:bankrec.approve')->name('reopen');
        Route::delete('/{bstmt}', 'destroy')->whereNumber('bstmt')->middleware('perm:bankrec.manage')->name('destroy');
        Route::post('/lines/{bline}/unmatch', 'unmatch')->whereNumber('bline')->middleware('perm:bankrec.manage')->name('lines.unmatch');
        Route::post('/lines/{bline}/ignore', 'ignore')->whereNumber('bline')->middleware('perm:bankrec.manage')->name('lines.ignore');
        Route::post('/lines/{bline}/unignore', 'unignore')->whereNumber('bline')->middleware('perm:bankrec.manage')->name('lines.unignore');
        Route::post('/lines/{bline}/entry', 'entry')->whereNumber('bline')->middleware('perm:bankrec.manage')->name('lines.entry');
        Route::delete('/lines/{bline}', 'deleteLine')->whereNumber('bline')->middleware('perm:bankrec.manage')->name('lines.delete');
    });

    // v4.7 petty cash (imprest) & staff expense claims
    Route::prefix('accounts/petty-cash')->name('petty.')->controller(Controllers\PettyCashController::class)->middleware('perm:petty.view')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/floats', 'storeFloat')->middleware('perm:petty.approve')->name('floats.store');
        Route::post('/settings', 'settings')->middleware('perm:petty.approve')->name('settings');
        Route::get('/{pfloat}', 'show')->whereNumber('pfloat')->name('show');
        Route::put('/{pfloat}', 'updateFloat')->whereNumber('pfloat')->middleware('perm:petty.approve')->name('floats.update');
        Route::post('/{pfloat}/active', 'toggleFloat')->whereNumber('pfloat')->middleware('perm:petty.approve')->name('floats.active');
        Route::post('/{pfloat}/voucher', 'expense')->whereNumber('pfloat')->middleware(['perm:petty.manage,petty.approve', 'throttle:60,1'])->name('expense');
        Route::post('/{pfloat}/iou', 'iou')->whereNumber('pfloat')->middleware('perm:petty.manage,petty.approve')->name('iou');
        Route::post('/{pfloat}/count', 'count')->whereNumber('pfloat')->middleware('perm:petty.manage,petty.approve')->name('count');
        Route::post('/{pfloat}/replenish', 'replenish')->whereNumber('pfloat')->middleware('perm:petty.manage,petty.approve')->name('replenish');
        Route::get('/txn/{ptxn}/print', 'printTxn')->whereNumber('ptxn')->name('txn.print');
        Route::get('/txn/{ptxn}/receipt', 'receipt')->whereNumber('ptxn')->name('txn.receipt');
        Route::post('/txn/{ptxn}/settle', 'settle')->whereNumber('ptxn')->middleware('perm:petty.manage,petty.approve')->name('txn.settle');
        Route::post('/txn/{ptxn}/approve', 'approve')->whereNumber('ptxn')->middleware('perm:petty.approve')->name('txn.approve');
        Route::post('/txn/{ptxn}/reject', 'reject')->whereNumber('ptxn')->middleware('perm:petty.approve')->name('txn.reject');
        Route::post('/txn/{ptxn}/void', 'void')->whereNumber('ptxn')->middleware('perm:petty.manage,petty.approve')->name('txn.void');
        Route::get('/replenishment/{prepl}/print', 'printRepl')->whereNumber('prepl')->name('repl.print');
        Route::post('/replenishment/{prepl}/approve', 'approveRepl')->whereNumber('prepl')->middleware('perm:petty.approve')->name('repl.approve');
        Route::post('/replenishment/{prepl}/reject', 'rejectRepl')->whereNumber('prepl')->middleware('perm:petty.approve')->name('repl.reject');
        Route::post('/replenishment/{prepl}/pay', 'payRepl')->whereNumber('prepl')->middleware('perm:petty.approve')->name('repl.pay');
        Route::post('/count/{pcount}/approve', 'approveCount')->whereNumber('pcount')->middleware('perm:petty.approve')->name('count.approve');
        Route::post('/count/{pcount}/reject', 'rejectCount')->whereNumber('pcount')->middleware('perm:petty.approve')->name('count.reject');
    });
    // v7.40 Car Rental — self-drive fleet + booking desk
    Route::prefix('rentals')->name('rentals.')->controller(Controllers\RentalController::class)->group(function () {
        Route::get('/', 'index')->middleware('perm:rentals.view')->name('index');
        Route::get('/fleet', 'fleet')->middleware('perm:rentals.view')->name('fleet');
        Route::middleware('perm:rentals.manage')->group(function () {
            Route::post('/', 'store')->name('store');
            Route::post('/vehicles', 'vehicleStore')->name('vehicles.store');
            Route::post('/vehicles/{vehicle}', 'vehicleUpdate')->whereNumber('vehicle')->name('vehicles.update');
            Route::post('/{rental}/pickup', 'pickup')->whereNumber('rental')->name('pickup');
            Route::post('/{rental}/return', 'return')->whereNumber('rental')->name('return');
            Route::post('/{rental}/cancel', 'cancel')->whereNumber('rental')->name('cancel');
            Route::post('/{rental}/bill', 'bill')->whereNumber('rental')->name('bill');
        });
    });

    Route::prefix('accounts/expense-claims')->name('claims.')->controller(Controllers\ExpenseClaimController::class)->middleware('perm:claims.submit,claims.approve,claims.pay')->group(function () {
        Route::get('/', 'index')->middleware('perm:claims.approve,claims.pay')->name('index');
        Route::get('/mine', 'mine')->name('mine');
        Route::post('/', 'store')->middleware('throttle:30,1')->name('store');
        Route::post('/settings', 'settings')->middleware('perm:claims.approve')->name('settings');
        Route::get('/{eclaim}', 'show')->whereNumber('eclaim')->name('show');
        Route::get('/{eclaim}/print', 'print')->whereNumber('eclaim')->name('print');
        Route::put('/{eclaim}', 'update')->whereNumber('eclaim')->name('update');
        Route::delete('/{eclaim}', 'destroy')->whereNumber('eclaim')->name('destroy');
        Route::post('/{eclaim}/lines', 'addLine')->whereNumber('eclaim')->middleware('throttle:60,1')->name('lines.add');
        Route::delete('/lines/{ecline}', 'deleteLine')->whereNumber('ecline')->name('lines.delete');
        Route::get('/lines/{ecline}/receipt', 'receipt')->whereNumber('ecline')->name('lines.receipt');
        Route::post('/{eclaim}/submit', 'submit')->whereNumber('eclaim')->name('submit');
        Route::post('/{eclaim}/approve', 'approve')->whereNumber('eclaim')->middleware('perm:claims.approve')->name('approve');
        Route::post('/{eclaim}/reject', 'reject')->whereNumber('eclaim')->middleware('perm:claims.approve')->name('reject');
        Route::post('/{eclaim}/pay', 'pay')->whereNumber('eclaim')->middleware('perm:claims.pay')->name('pay');
    });

    Route::prefix('ap')->name('ap.')->controller(Controllers\ApController::class)->middleware('perm:ap.view')->group(function () {
        Route::get('/bills', 'bills')->name('bills');
        Route::get('/bills/create', 'create')->middleware('perm:ap.manage')->name('bills.create');
        Route::post('/bills', 'store')->middleware(['perm:ap.manage', 'throttle:60,1'])->name('bills.store');
        Route::get('/bills/{bill}', 'show')->whereNumber('bill')->name('bills.show');
        Route::post('/bills/{bill}/approve', 'approve')->whereNumber('bill')->middleware('perm:ap.approve')->name('bills.approve');
        Route::post('/bills/{bill}/cancel', 'cancel')->whereNumber('bill')->middleware('perm:ap.manage')->name('bills.cancel');
        Route::post('/bills/{bill}/apply', 'apply')->whereNumber('bill')->middleware('perm:ap.pay')->name('bills.apply');
        Route::delete('/allocations/{alloc}', 'unallocate')->whereNumber('alloc')->middleware('perm:ap.pay')->name('allocations.delete');
        Route::get('/payments', 'payments')->name('payments');
        Route::get('/payments/create', 'payCreate')->middleware('perm:ap.pay')->name('payments.create');
        Route::post('/payments', 'payStore')->middleware(['perm:ap.pay', 'throttle:60,1'])->name('payments.store');
        Route::get('/payments/{spay}', 'payShow')->whereNumber('spay')->name('payments.show');
        Route::post('/payments/{spay}/cancel', 'payCancel')->whereNumber('spay')->middleware('perm:ap.pay')->name('payments.cancel');
        Route::get('/ageing', 'ageing')->name('ageing');
        Route::get('/suppliers/{supplier}/statement', 'statement')->whereNumber('supplier')->name('statement');
        // v4.4 Ghana withholding tax
        Route::get('/wht', [Controllers\WhtController::class, 'index'])->name('wht');
        Route::get('/wht/export', [Controllers\WhtController::class, 'export'])->name('wht.export');
        Route::get('/wht/quote', [Controllers\WhtController::class, 'quote'])->middleware('perm:ap.pay')->name('wht.quote');
        Route::post('/wht/file', [Controllers\WhtController::class, 'file'])->middleware('perm:ap.approve')->name('wht.file');
        Route::post('/wht/settings', [Controllers\WhtController::class, 'settings'])->middleware('perm:ap.approve')->name('wht.settings');
        Route::get('/payments/{spay}/wht-certificate', [Controllers\WhtController::class, 'certificate'])->whereNumber('spay')->name('payments.certificate');
    });

    // v1.6 general ledger
    Route::prefix('gl')->name('gl.')->controller(Controllers\GlController::class)->middleware('perm:gl.view')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/accounts/{head}', 'ledger')->whereNumber('head')->name('ledger');
        Route::get('/journals', 'journals')->name('journals');
        Route::get('/journals/create', 'create')->middleware('perm:gl.journal')->name('journals.create');
        Route::post('/journals', 'store')->middleware(['perm:gl.journal', 'throttle:30,1'])->name('journals.store');
        Route::get('/journals/{journal}', 'show')->whereNumber('journal')->name('journals.show');
        Route::post('/journals/{journal}/reverse', 'reverse')->whereNumber('journal')->middleware('perm:gl.journal')->name('journals.reverse');
        Route::post('/sync', 'sync')->middleware(['perm:gl.manage', 'throttle:10,1'])->name('sync');
        Route::post('/lock', 'lock')->middleware('perm:gl.manage')->name('lock');
        Route::get('/mapping', 'mapping')->middleware('perm:gl.manage')->name('mapping');
        Route::post('/mapping', 'saveMapping')->middleware('perm:gl.manage')->name('mapping.save');
    });

    // v5.6 financial close – periods & checklist, schedules, recurring journals, year end
    Route::controller(Controllers\CloseController::class)->prefix('close')->name('close.')->group(function () {
        Route::middleware('perm:close.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/accruals', 'accruals')->name('accruals');
            Route::get('/accruals/{accrual}', 'accrual')->whereNumber('accrual')->name('accruals.show');
            Route::get('/recurring', 'recurring')->name('recurring');
            Route::get('/year-end', 'yearEnd')->name('yearend');
            Route::get('/{month}', 'show')->where('month', '\d{4}-\d{2}')->name('show');
        });
        Route::middleware('perm:close.prepare')->group(function () {
            Route::post('/{month}/check', 'check')->where('month', '\d{4}-\d{2}')->middleware('throttle:20,1')->name('check');
            Route::post('/{month}/post', 'post')->where('month', '\d{4}-\d{2}')->middleware('throttle:10,1')->name('post');
            Route::post('/{month}/tasks', 'addTask')->where('month', '\d{4}-\d{2}')->name('tasks.add');
            Route::post('/{month}/submit', 'submit')->where('month', '\d{4}-\d{2}')->name('submit');
            Route::post('/{month}/reopen-request', 'reopenRequest')->where('month', '\d{4}-\d{2}')->name('reopen.request');
            Route::post('/tasks/{closetask}/done', 'taskDone')->name('tasks.done');
            Route::post('/tasks/{closetask}/waive', 'taskWaive')->name('tasks.waive');
            Route::post('/tasks/{closetask}/reset', 'taskReset')->name('tasks.reset');
            Route::post('/accruals', 'storeAccrual')->middleware('throttle:30,1')->name('accruals.store');
            Route::post('/recurring', 'storeRecurring')->middleware('throttle:30,1')->name('recurring.store');
        });
        Route::post('/accruals/{accrual}/cancel', 'cancelAccrual')->middleware('perm:close.prepare,close.approve')->name('accruals.cancel');
        Route::middleware('perm:close.approve')->group(function () {
            Route::post('/{month}/approve', 'approve')->where('month', '\d{4}-\d{2}')->name('approve');
            Route::post('/{month}/reject', 'reject')->where('month', '\d{4}-\d{2}')->name('reject');
            Route::post('/{month}/reopen-approve', 'reopenApprove')->where('month', '\d{4}-\d{2}')->name('reopen.approve');
            Route::post('/{month}/reopen-deny', 'reopenDeny')->where('month', '\d{4}-\d{2}')->name('reopen.deny');
            Route::post('/accruals/{accrual}/decide', 'decideAccrual')->name('accruals.decide');
            Route::post('/recurring/{rjournal}/decide', 'decideRecurring')->name('recurring.decide');
            Route::post('/recurring/{rjournal}/status', 'recurringStatus')->name('recurring.status');
            Route::post('/settings', 'settings')->name('settings');
        });
    });

    // v5.7 treasury & cash management
    Route::controller(Controllers\TreasuryController::class)->prefix('treasury')->name('treasury.')->group(function () {
        Route::middleware('perm:treasury.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/transfers', 'transfers')->name('transfers');
            Route::get('/transfers/{transfer}', 'showTransfer')->whereNumber('transfer')->name('transfers.show');
            Route::get('/suggest', 'suggest')->name('suggest');
            Route::get('/cheques', 'cheques')->name('cheques');
            Route::get('/forecast', 'forecast')->name('forecast');
            Route::get('/cash-flow', 'cashFlow')->name('cashflow');
            Route::get('/accounts', 'accounts')->name('accounts');
        });
        Route::middleware('perm:treasury.operate')->group(function () {
            Route::post('/transfers', 'storeTransfer')->name('transfers.store');
            Route::post('/transfers/{transfer}/dispatch', 'dispatchTransfer')->name('transfers.dispatch');
            Route::post('/transfers/{transfer}/complete', 'complete')->name('transfers.complete');
            Route::post('/transfers/{transfer}/cancel', 'cancel')->name('transfers.cancel');
            Route::post('/cheques/received', 'receiveCheque')->name('cheques.receive');
            Route::post('/cheques/received/{cheque}/bank-pdc', 'bankPdc')->name('cheques.pdc');
            Route::post('/cheques/received/{cheque}/clear', 'clearCheque')->name('cheques.clear');
            Route::post('/cheques/received/{cheque}/bounce', 'bounce')->name('cheques.bounce');
            Route::post('/cheques/received/{cheque}/return', 'returnCheque')->name('cheques.return');
            Route::post('/cheques/issued', 'issue')->name('cheques.issue');
            Route::post('/cheques/issued/{issued}/void', 'voidIssued')->name('cheques.void');
            Route::post('/cheques/issued/{issued}/clear', 'clearIssued')->name('cheques.presented');
            Route::post('/cheques/books/{book}/spoil', 'spoil')->name('cheques.spoil');
            Route::post('/cheques/sync', 'sync')->name('cheques.sync');
            Route::post('/forecast/lines', 'storeLine')->name('forecast.store');
            Route::put('/forecast/lines/{fline}', 'updateLine')->name('forecast.update');
            Route::delete('/forecast/lines/{fline}', 'deleteLine')->name('forecast.delete');
        });
        Route::middleware('perm:treasury.approve')->group(function () {
            Route::post('/transfers/{transfer}/approve', 'approve')->name('transfers.approve');
            Route::post('/transfers/{transfer}/reject', 'reject')->name('transfers.reject');
        });
        Route::middleware('perm:treasury.manage')->group(function () {
            Route::post('/accounts', 'storeAccount')->name('accounts.store');
            Route::put('/accounts/{taccount}', 'updateAccount')->name('accounts.update');
            Route::post('/cheques/books', 'addBook')->name('cheques.books');
            Route::post('/settings', 'settings')->name('settings');
            Route::post('/cf-classes', 'cfClasses')->name('cfclasses');
        });
    });

    // v5.8 Owner reporting & USALI management accounts
    // v6.0 learning & development: training matrix, courses, sessions, records, SOP library
    Route::controller(Controllers\LearningController::class)->prefix('learning')->name('learning.')->group(function () {
        Route::middleware('perm:learning.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/matrix', 'matrix')->name('matrix');
            Route::get('/matrix/print', 'matrixPrint')->name('matrix.print');
            Route::get('/staff/{employee}', 'employee')->whereNumber('employee')->name('employee');
            Route::get('/courses', 'courses')->name('courses');
            Route::get('/courses/{tcourse}', 'course')->whereNumber('tcourse')->name('course');
            Route::get('/sessions', 'sessions')->name('sessions');
            Route::get('/sessions/{tsession}', 'session')->whereNumber('tsession')->name('session');
            Route::get('/sessions/{tsession}/sheet', 'sessionSheet')->whereNumber('tsession')->name('session.sheet');
            Route::get('/records', 'records')->name('records');
            Route::get('/records/{trecord}/certificate', 'certificate')->whereNumber('trecord')->name('certificate');
            Route::get('/sops', 'sops')->name('sops');
            Route::get('/sops/{sop}', 'sop')->whereNumber('sop')->name('sop');
            Route::get('/sops/{sop}/print', 'sopPrint')->whereNumber('sop')->name('sop.print');
            Route::get('/sops/{sop}/sheet', 'sopSheet')->whereNumber('sop')->name('sop.sheet');
        });
        Route::middleware('perm:learning.train')->group(function () {
            Route::post('/sessions', 'sessionStore')->name('sessions.store');
            Route::post('/sessions/{tsession}/enrol', 'enrol')->whereNumber('tsession')->name('session.enrol');
            Route::post('/sessions/{tsession}/complete', 'complete')->whereNumber('tsession')->name('session.complete');
            Route::post('/sessions/{tsession}/cancel', 'cancelSession')->whereNumber('tsession')->name('session.cancel');
            Route::post('/records/{trecord}/unenrol', 'unenrol')->whereNumber('trecord')->name('unenrol');
            Route::post('/records', 'recordStore')->name('records.store');
            Route::post('/sops/{sop}/sheet', 'sopRecordSheet')->whereNumber('sop')->name('sop.sheet.store');
        });
        Route::middleware('perm:learning.sop')->group(function () {
            Route::get('/sops/new', 'sopCreate')->name('sop.create');
            Route::get('/sops/{sop}/edit', 'sopEdit')->whereNumber('sop')->name('sop.edit');
            Route::post('/sops', 'sopStore')->name('sop.store');
            Route::put('/sops/{sop}', 'sopUpdate')->whereNumber('sop')->name('sop.update');
            Route::post('/sops/{sop}/publish', 'sopPublish')->whereNumber('sop')->name('sop.publish');
            Route::post('/sops/{sop}/review', 'sopReview')->whereNumber('sop')->name('sop.review');
            Route::post('/sops/{sop}/discard', 'sopDiscard')->whereNumber('sop')->name('sop.discard');
            Route::post('/sops/{sop}/retire', 'sopRetire')->whereNumber('sop')->name('sop.retire');
        });
        Route::middleware('perm:learning.manage')->group(function () {
            Route::post('/courses', 'courseStore')->name('courses.store');
            Route::put('/courses/{tcourse}', 'courseUpdate')->whereNumber('tcourse')->name('course.update');
            Route::post('/courses/{tcourse}/requirements', 'requirementStore')->whereNumber('tcourse')->name('requirement.store');
            Route::delete('/requirements/{treq}', 'requirementDestroy')->whereNumber('treq')->name('requirement.destroy');
            Route::post('/exempt', 'exempt')->name('exempt');
            Route::post('/records/{trecord}/delete', 'recordDelete')->whereNumber('trecord')->name('record.delete');
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'saveSettings')->name('settings.save');
        });
    });
    Route::controller(Controllers\LearningController::class)->prefix('me')->name('me.')->middleware('perm:hr.self')->group(function () {
        Route::get('/learning', 'mine')->name('learning');
        Route::get('/sops/{sop}', 'mySop')->whereNumber('sop')->name('sop');
        Route::post('/sops/{sop}/sign', 'mySign')->whereNumber('sop')->middleware('throttle:30,1')->name('sop.sign');
        Route::get('/certificates/{trecord}', 'myCertificate')->whereNumber('trecord')->name('certificate');
    });

    // ---- v6.1 Marketing plan: calendar of marketing events, budget vs actual, collateral library
    Route::controller(Controllers\MarketingPlanController::class)->prefix('sales')->name('sales.')->group(function () {
        Route::middleware('perm:sales.view')->group(function () {
            Route::get('/marketing', 'index')->name('marketing');
            Route::get('/marketing/{mevent}', 'show')->whereNumber('mevent')->name('mevent');
            Route::get('/marketing-budget', 'budget')->name('budget');
            Route::get('/collateral', 'collateral')->name('collateral');
            Route::get('/collateral/{mcollateral}/download', 'download')->whereNumber('mcollateral')->name('collateral.download');
        });
        Route::middleware('perm:sales.marketing,sales.manage')->group(function () {
            Route::post('/marketing', 'store')->name('mevents.store');
            Route::put('/marketing/{mevent}', 'update')->whereNumber('mevent')->name('mevent.update');
            Route::post('/marketing/{mevent}/status', 'status')->whereNumber('mevent')->name('mevent.status');
            Route::post('/marketing-budget', 'saveBudget')->name('budget.save');
            Route::post('/collateral', 'collateralStore')->name('collateral.store');
            Route::post('/collateral/{mcollateral}', 'collateralUpdate')->whereNumber('mcollateral')->name('collateral.update');
            Route::post('/collateral/{mcollateral}/delete', 'collateralDelete')->whereNumber('mcollateral')->name('collateral.delete');
        });
    });

    // ---- v6.2 Digital marketing: dashboard, social content calendar & approval, paid campaigns, tracked links, channels & listings, influencers
    // v6.3 business development – competitive set, market mix, partnerships, initiatives
    // v6.4 central reservations & lost business: group availability & booking, enquiry log (denials / regrets), referrals, guest lookup, KPIs
    Route::controller(Controllers\CroController::class)->prefix('cro')->name('cro.')->group(function () {
        Route::middleware('perm:cro.view,cro.book,cro.manage')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/enquiries', 'enquiries')->name('enquiries');
            Route::get('/enquiries/{croenquiry}', 'show')->whereNumber('croenquiry')->name('enquiry');
            Route::get('/guests', 'guests')->name('guests');
            Route::get('/lost', 'lost')->name('lost');
            Route::get('/performance', 'performance')->name('performance');
        });
        Route::middleware('perm:cro.book,cro.manage')->group(function () {
            Route::post('/book', 'book')->name('book');
            Route::post('/enquiries', 'store')->name('enquiries.store');
            Route::post('/enquiries/{croenquiry}/lost', 'markLost')->whereNumber('croenquiry')->name('enquiry.lost');
            Route::post('/enquiries/{croenquiry}/reopen', 'reopen')->whereNumber('croenquiry')->name('enquiry.reopen');
            Route::post('/enquiries/{croenquiry}/contact', 'contact')->whereNumber('croenquiry')->name('enquiry.contact');
        });
        Route::middleware('perm:cro.manage')->group(function () {
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'settingsSave')->name('settings.save');
        });
    });

    // v6.8 F&B cost control & menu engineering: recipe cards & plate cost, menu engineering, cost statement, usage variance, pricing, yields, staff meals
    Route::controller(Controllers\FbCostController::class)->prefix('fbc')->name('fbc.')->group(function () {
        Route::middleware('perm:fbc.view,fbc.cost,fbc.price')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/recipes', 'recipes')->name('recipes');
            Route::get('/recipes/{fbdish}', 'card')->whereNumber('fbdish')->name('card');
            Route::get('/recipes/{fbdish}/print', 'cardPrint')->whereNumber('fbdish')->name('card.print');
            Route::get('/menu-engineering', 'menu')->name('menu');
            Route::get('/statement', 'statement')->name('statement');
            Route::get('/variance', 'variance')->name('variance');
            Route::get('/pricing', 'pricing')->name('pricing');
            Route::get('/yields', 'yields')->name('yields');
            Route::get('/staff-meals', 'meals')->name('meals');
        });
        Route::middleware('perm:fbc.cost,fbc.price')->group(function () {
            Route::get('/recipes/{fbdish}/edit', 'cardEdit')->whereNumber('fbdish')->name('card.edit');
            Route::post('/recipes/{fbdish}', 'cardSave')->whereNumber('fbdish')->name('card.save');
            Route::post('/yields', 'yieldStore')->name('yields.store');
            Route::post('/yields/{fbyield}/apply', 'yieldApply')->whereNumber('fbyield')->name('yields.apply');
            Route::post('/staff-meals', 'mealSave')->name('meals.store');
            Route::post('/staff-meals/{fbmeal}/delete', 'mealDelete')->whereNumber('fbmeal')->name('meals.delete');
        });
        Route::middleware('perm:fbc.price')->group(function () {
            Route::post('/recipes/{fbdish}/approve', 'cardApprove')->whereNumber('fbdish')->name('card.approve');
            Route::post('/pricing/{fbdish}', 'priceChange')->whereNumber('fbdish')->name('pricing.change');
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'settingsSave')->name('settings.save');
        });
    });

    // v6.9 talent management: recruitment, new starters, appraisals, discipline & grievances, exits
    Route::controller(Controllers\TalentController::class)->prefix('talent')->name('talent.')->group(function () {
        Route::middleware('perm:talent.view,talent.recruit,talent.approve,talent.appraise,talent.discipline,talent.manage')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/vacancies', 'vacancies')->name('vacancies');
            Route::get('/vacancies/{tvacancy}', 'vacancy')->whereNumber('tvacancy')->name('vacancy');
            Route::get('/establishment', 'establishment')->name('establishment');
            Route::get('/interviews/{tinterview}', 'interview')->whereNumber('tinterview')->name('interview');
            Route::post('/interviews/{tinterview}/score', 'score')->whereNumber('tinterview')->name('interview.score');
            Route::get('/applicants/{tapplicant}/cv', 'cv')->whereNumber('tapplicant')->name('applicant.cv');
        });
        Route::middleware('perm:talent.recruit,talent.approve')->group(function () {
            Route::get('/applicants', 'applicants')->name('applicants');
            Route::get('/applicants/{tapplicant}', 'applicant')->whereNumber('tapplicant')->name('applicant');
            Route::get('/offers/{toffer}/letter', 'offerLetter')->whereNumber('toffer')->name('offer.letter');
            Route::post('/vacancies', 'vacancyStore')->name('vacancies.store');
            Route::post('/vacancies/{tvacancy}', 'vacancyUpdate')->whereNumber('tvacancy')->name('vacancy.update');
            Route::post('/vacancies/{tvacancy}/submit', 'vacancySubmit')->whereNumber('tvacancy')->name('vacancy.submit');
            Route::post('/vacancies/{tvacancy}/status', 'vacancyStatus')->whereNumber('tvacancy')->name('vacancy.status');
            Route::post('/vacancies/{tvacancy}/applicants', 'applicantStore')->whereNumber('tvacancy')->name('applicants.store');
            Route::post('/applicants/{tapplicant}/move', 'applicantMove')->whereNumber('tapplicant')->name('applicant.move');
            Route::post('/applicants/{tapplicant}/interviews', 'interviewStore')->whereNumber('tapplicant')->name('interviews.store');
            Route::post('/interviews/{tinterview}/status', 'interviewStatus')->whereNumber('tinterview')->name('interview.status');
            Route::post('/applicants/{tapplicant}/offer', 'offerStore')->whereNumber('tapplicant')->name('offer.store');
            Route::post('/offers/{toffer}/respond', 'offerRespond')->whereNumber('toffer')->name('offer.respond');
        });
        Route::middleware('perm:talent.approve')->group(function () {
            Route::post('/vacancies/{tvacancy}/approve', 'vacancyApprove')->whereNumber('tvacancy')->name('vacancy.approve');
            Route::post('/vacancies/{tvacancy}/return', 'vacancyReturn')->whereNumber('tvacancy')->name('vacancy.return');
            Route::post('/offers/{toffer}/approve', 'offerApprove')->whereNumber('toffer')->name('offer.approve');
        });
        Route::middleware('perm:talent.recruit,talent.appraise')->group(function () {
            Route::get('/starters', 'starters')->name('starters');
            Route::get('/starters/{temployee}', 'starter')->whereNumber('temployee')->name('starter');
            Route::get('/starters/{temployee}/statement', 'statement')->whereNumber('temployee')->name('statement');
            Route::post('/starters/{temployee}/statement', 'statementIssue')->whereNumber('temployee')->name('statement.issue');
            Route::post('/tasks/{ttask}', 'taskToggle')->whereNumber('ttask')->name('task.toggle');
        });
        Route::middleware('perm:talent.appraise')->group(function () {
            Route::post('/starters/{temployee}/probation', 'probation')->whereNumber('temployee')->name('probation');
            Route::get('/appraisals', 'appraisals')->name('appraisals');
            Route::get('/appraisals/cycles/{tcycle}', 'cycle')->whereNumber('tcycle')->name('cycle');
            Route::post('/appraisals/cycles', 'cycleStore')->name('cycles.store');
            Route::post('/appraisals/cycles/{tcycle}/close', 'cycleClose')->whereNumber('tcycle')->name('cycle.close');
            Route::post('/appraisals/{tappraisal}/reviewer', 'reviewer')->whereNumber('tappraisal')->name('appraisal.reviewer');
            Route::post('/appraisals/{tappraisal}/signoff', 'signoff')->whereNumber('tappraisal')->name('appraisal.signoff');
        });
        Route::middleware('perm:hr.self,talent.view,talent.appraise')->group(function () {
            Route::get('/me', 'me')->name('me');
            Route::get('/appraisals/{tappraisal}', 'appraisal')->whereNumber('tappraisal')->name('appraisal');
            Route::post('/appraisals/{tappraisal}/self', 'selfSave')->whereNumber('tappraisal')->name('appraisal.self');
            Route::post('/appraisals/{tappraisal}/review', 'reviewSave')->whereNumber('tappraisal')->name('appraisal.review');
            Route::post('/appraisals/{tappraisal}/acknowledge', 'acknowledge')->whereNumber('tappraisal')->name('appraisal.acknowledge');
        });
        Route::middleware('perm:talent.discipline')->group(function () {
            Route::get('/cases', 'cases')->name('cases');
            Route::get('/cases/{tcase}', 'caseShow')->whereNumber('tcase')->name('case');
            Route::get('/cases/{tcase}/letter/{kind}', 'caseLetter')->whereNumber('tcase')->where('kind', 'query|outcome')->name('case.letter');
            Route::post('/cases', 'caseStore')->name('cases.store');
            Route::post('/cases/{tcase}/{step}', 'caseAction')->whereNumber('tcase')->where('step', 'query|reply|hearing|held|decide|investigating|resolved|withdrawn|close')->name('case.action');
            Route::get('/exits', 'exits')->name('exits');
            Route::get('/exits/{texit}', 'exitShow')->whereNumber('texit')->name('exit');
            Route::get('/exits/{texit}/letter', 'exitLetter')->whereNumber('texit')->name('exit.letter');
            Route::post('/exits', 'exitStore')->name('exits.store');
            Route::post('/exits/{texit}/{step}', 'exitAction')->whereNumber('texit')->where('step', 'clearance|interview|complete|withdraw')->name('exit.action');
        });
        Route::middleware('perm:talent.manage')->group(function () {
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'settingsSave')->name('settings.save');
            Route::post('/establishment', 'establishmentSave')->name('establishment.save');
            Route::post('/managers', 'managersSave')->name('managers.save');
        });
    });

    // v6.7 health, safety & fire: equipment & inspection round, drills & wardens, risk assessments, permits to work, accidents
    Route::controller(Controllers\HseController::class)->prefix('hse')->name('hse.')->group(function () {
        Route::middleware('perm:hse.view,hse.check,hse.permit,hse.manage')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/equipment', 'equipment')->name('equipment');
            Route::get('/equipment/{hseequipment}', 'equipmentShow')->whereNumber('hseequipment')->name('equipment.show');
            Route::get('/permits', 'permits')->name('permits');
            Route::get('/permits/{hsepermit}', 'permit')->whereNumber('hsepermit')->name('permit');
            Route::get('/permits/{hsepermit}/print', 'permitPrint')->whereNumber('hsepermit')->name('permit.print');
            Route::get('/accidents', 'accidents')->name('accidents');
            Route::get('/accidents/{hseaccident}', 'accident')->whereNumber('hseaccident')->name('accident');
            Route::get('/risks', 'risks')->name('risks');
            Route::get('/risks/{hserisk}', 'risk')->whereNumber('hserisk')->name('risk');
            Route::get('/drills', 'drills')->name('drills');
            Route::get('/drills/{hsedrill}', 'drill')->whereNumber('hsedrill')->name('drill');
        });
        Route::middleware('perm:hse.check,hse.permit,hse.manage')->group(function () {
            Route::get('/round', 'round')->name('round');
            Route::post('/round', 'roundSave')->name('round.save');
            Route::post('/equipment/{hseequipment}/check', 'check')->whereNumber('hseequipment')->name('equipment.check');
            Route::post('/permits', 'permitStore')->name('permits.store');
            Route::post('/permits/{hsepermit}/cancel', 'permitCancel')->whereNumber('hsepermit')->name('permits.cancel');
            Route::post('/accidents', 'accidentStore')->name('accidents.store');
            Route::post('/drills/{hsedrill}/record', 'drillRecord')->whereNumber('hsedrill')->name('drills.record');
            Route::post('/risks/{hserisk}/review', 'riskReview')->whereNumber('hserisk')->name('risks.review');
        });
        Route::middleware('perm:hse.permit,hse.manage')->group(function () {
            Route::post('/permits/{hsepermit}/issue', 'permitIssue')->whereNumber('hsepermit')->name('permits.issue');
            Route::post('/permits/{hsepermit}/close', 'permitClose')->whereNumber('hsepermit')->name('permits.close');
        });
        Route::middleware('perm:hse.manage')->group(function () {
            Route::get('/accidents/{hseaccident}/notice', 'notice')->whereNumber('hseaccident')->name('accident.notice');
            Route::post('/equipment', 'equipmentSave')->name('equipment.store');
            Route::post('/equipment/{hseequipment}', 'equipmentSave')->whereNumber('hseequipment')->name('equipment.update');
            Route::post('/accidents/{hseaccident}', 'accidentUpdate')->whereNumber('hseaccident')->name('accidents.update');
            Route::post('/accidents/{hseaccident}/notify', 'accidentNotify')->whereNumber('hseaccident')->name('accidents.notify');
            Route::post('/accidents/{hseaccident}/investigate', 'accidentInvestigate')->whereNumber('hseaccident')->name('accidents.investigate');
            Route::post('/accidents/{hseaccident}/close', 'accidentClose')->whereNumber('hseaccident')->name('accidents.close');
            Route::post('/risks', 'riskSave')->name('risks.store');
            Route::post('/risks/{hserisk}', 'riskSave')->whereNumber('hserisk')->name('risks.update');
            Route::post('/risks/{hserisk}/accept', 'riskAccept')->whereNumber('hserisk')->name('risks.accept');
            Route::post('/risks/{hserisk}/archive', 'riskArchive')->whereNumber('hserisk')->name('risks.archive');
            Route::post('/drills', 'drillStore')->name('drills.store');
            Route::post('/drills/{hsedrill}/cancel', 'drillCancel')->whereNumber('hsedrill')->name('drills.cancel');
            Route::post('/wardens', 'wardenSave')->name('wardens.store');
            Route::post('/wardens/{hsewarden}', 'wardenSave')->whereNumber('hsewarden')->name('wardens.update');
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'saveSettings')->name('settings.save');
        });
    });

    // v7.0 sustainability & ESG: carbon (HCMI / HWMI), waste, F-gas, green stay, targets, initiatives, people & community, certification, policies, statements, annual report
    Route::controller(Controllers\EsgController::class)->prefix('esg')->name('esg.')->group(function () {
        Route::middleware('perm:esg.view,esg.record,esg.green,esg.manage')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/carbon', 'carbon')->name('carbon');
            Route::get('/waste', 'waste')->name('waste');
            Route::get('/refrigerants', 'refrigerants')->name('refrigerants');
            Route::get('/green', 'green')->name('green');
            Route::get('/targets', 'targets')->name('targets');
            Route::get('/initiatives', 'initiatives')->name('initiatives');
            Route::get('/initiatives/{esginitiative}', 'initiative')->whereNumber('esginitiative')->name('initiative');
            Route::get('/social', 'social')->name('social');
            Route::get('/certification', 'certification')->name('certification');
            Route::get('/policies', 'policies')->name('policies');
            Route::get('/policies/{esgpolicy}/file', 'policyFile')->whereNumber('esgpolicy')->name('policy.file');
            Route::get('/community/{esgcommunity}/evidence', 'communityFile')->whereNumber('esgcommunity')->name('community.file');
            Route::get('/statements', 'statements')->name('statements');
            Route::get('/statements/print', 'statementPrint')->name('statement.print');
            Route::get('/report', 'report')->name('report');
            Route::get('/report/print', 'reportPrint')->name('report.print');
        });
        Route::middleware('perm:esg.green,esg.manage')->group(function () {
            Route::post('/green', 'greenIn')->name('green.in');
            Route::post('/green/{esggreen}/end', 'greenOut')->whereNumber('esggreen')->name('green.out');
        });
        Route::middleware('perm:esg.record,esg.manage')->group(function () {
            Route::post('/waste', 'wasteStore')->name('waste.store');
            Route::post('/waste/{esgwaste}/cancel', 'wasteCancel')->whereNumber('esgwaste')->name('waste.cancel');
            Route::post('/refrigerants', 'refrigerantStore')->name('refrigerants.store');
            Route::post('/community', 'communityStore')->name('community.store');
            Route::post('/initiatives', 'initiativeStore')->name('initiatives.store');
            Route::post('/initiatives/{esginitiative}', 'initiativeUpdate')->whereNumber('esginitiative')->name('initiatives.update');
            Route::post('/initiatives/{esginitiative}/move', 'initiativeMove')->whereNumber('esginitiative')->name('initiatives.move');
        });
        Route::middleware('perm:esg.manage')->group(function () {
            Route::post('/laundry', 'laundryStore')->name('laundry.store');
            Route::post('/targets', 'targetStore')->name('targets.store');
            Route::post('/targets/{esgtarget}', 'targetUpdate')->whereNumber('esgtarget')->name('targets.update');
            Route::post('/certification/{esgitem}', 'assess')->whereNumber('esgitem')->name('certification.assess');
            Route::post('/policies', 'policyStore')->name('policies.store');
            Route::post('/policies/{esgpolicy}', 'policyUpdate')->whereNumber('esgpolicy')->name('policies.update');
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'saveSettings')->name('settings.save');
        });
    });
    // v7.1 IPTV & guest welcome: TVs & pairing, remote commands, channels / EPG, movie rentals, welcome screens, pages, TV messages & emergency alert
    Route::controller(Controllers\IptvController::class)->prefix('iptv')->name('iptv.')->group(function () {
        Route::middleware('perm:iptv.view,iptv.message,iptv.emergency,iptv.manage')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/devices', 'devices')->name('devices');
            Route::get('/devices/{tvdevice}', 'device')->whereNumber('tvdevice')->name('device');
            Route::get('/channels', 'channels')->name('channels');
            Route::get('/channels/export', 'm3uExport')->name('channels.export');
            Route::get('/movies', 'movies')->name('movies');
            Route::get('/welcome', 'welcome')->name('welcome');
            Route::get('/pages', 'pages')->name('pages');
            Route::get('/messages', 'messages')->name('messages');
            Route::get('/analytics', 'analytics')->name('analytics');
        });
        Route::middleware('perm:iptv.message,iptv.manage')->group(function () {
            Route::post('/messages', 'messageStore')->name('messages.store');
            Route::post('/messages/{tvmessage}/cancel', 'messageCancel')->whereNumber('tvmessage')->name('messages.cancel');
            Route::post('/stay/{stay}/reset', 'stayReset')->whereNumber('stay')->name('stay.reset');
        });
        Route::middleware('perm:iptv.emergency,iptv.manage')->group(function () {
            Route::post('/emergency', 'emergency')->name('emergency');
            Route::post('/emergency/{tvmessage}/clear', 'emergencyClear')->whereNumber('tvmessage')->name('emergency.clear');
        });
        Route::middleware('perm:iptv.manage')->group(function () {
            Route::post('/devices', 'deviceStore')->name('devices.store');
            Route::post('/devices/pair', 'pair')->name('devices.pair');
            Route::put('/devices/{tvdevice}', 'deviceUpdate')->whereNumber('tvdevice')->name('devices.update');
            Route::post('/devices/{tvdevice}/rotate', 'rotate')->whereNumber('tvdevice')->name('devices.rotate');
            Route::post('/devices/{tvdevice}/status', 'status')->whereNumber('tvdevice')->name('devices.status');
            Route::post('/devices/{tvdevice}/command', 'command')->whereNumber('tvdevice')->name('devices.command');
            Route::delete('/devices/{tvdevice}', 'deviceDelete')->whereNumber('tvdevice')->name('devices.delete');
            Route::post('/channels', 'channelStore')->name('channels.store');
            Route::put('/channels/{tvchannel}', 'channelUpdate')->whereNumber('tvchannel')->name('channels.update');
            Route::delete('/channels/{tvchannel}', 'channelDelete')->whereNumber('tvchannel')->name('channels.delete');
            Route::post('/channels/import', 'm3uImport')->name('channels.import');
            Route::post('/channels/epg', 'epgImport')->name('channels.epg');
            Route::post('/movies', 'movieStore')->name('movies.store');
            Route::put('/movies/{tvmovie}', 'movieUpdate')->whereNumber('tvmovie')->name('movies.update');
            Route::post('/rentals/{tvpurchase}/refund', 'refund')->whereNumber('tvpurchase')->name('rentals.refund');
            Route::post('/welcome', 'templateStore')->name('welcome.store');
            Route::put('/welcome/{tvtemplate}', 'templateUpdate')->whereNumber('tvtemplate')->name('welcome.update');
            Route::post('/pages', 'pageStore')->name('pages.store');
            Route::put('/pages/{tvpage}', 'pageUpdate')->whereNumber('tvpage')->name('pages.update');
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'saveSettings')->name('settings.save');
        });
    });

    // v7.3 guest messaging: shared inbox (portal chat, WhatsApp, SMS, e-mail), dashboard, broadcasts, quick replies, routing rules, settings
    // ---- v7.4 Partner portal (hotel side)
    Route::controller(Controllers\PartnerAdminController::class)->prefix('partner-admin')->name('partner-admin.')->group(function () {
        Route::get('/', 'dashboard')->middleware('perm:partner.view')->name('dashboard');
        Route::get('/accounts', 'accounts')->middleware('perm:partner.view')->name('accounts');
        Route::get('/accounts/{company}', 'company')->middleware('perm:partner.view')->name('company');
        Route::post('/accounts/{company}/terms', 'terms')->middleware('perm:partner.manage')->name('terms');
        Route::post('/accounts/{company}/invite', 'invite')->middleware('perm:partner.manage')->name('invite');
        Route::post('/users/{puser}', 'user')->middleware('perm:partner.manage')->name('user');
        Route::get('/requests', 'requests')->middleware('perm:partner.view')->name('requests');
        Route::post('/requests/{partnerreq}', 'decide')->middleware('perm:partner.approve')->name('decide');
        Route::get('/activity', 'activity')->middleware('perm:partner.view')->name('activity');
        Route::get('/settings', 'settings')->middleware('perm:partner.manage')->name('settings');
        Route::post('/settings', 'saveSettings')->middleware('perm:partner.manage')->name('settings.save');
    });

    // v7.5 event organiser portal (staff side): dashboard, portal events + BEO publishing, organiser logins, request queue, activity, settings
    Route::controller(Controllers\OrganiserAdminController::class)->prefix('organiser-admin')->name('organiser-admin.')->group(function () {
        Route::get('/', 'dashboard')->middleware('perm:organiser.view')->name('dashboard');
        Route::get('/events', 'events')->middleware('perm:organiser.view')->name('events');
        Route::get('/events/{bevent}', 'event')->middleware('perm:organiser.view')->name('event');
        Route::post('/events/{bevent}/portal', 'portal')->middleware('perm:organiser.manage')->name('portal');
        Route::post('/events/{bevent}/beo', 'publish')->middleware('perm:organiser.manage')->name('beo.publish');
        Route::get('/organisers', 'organisers')->middleware('perm:organiser.view')->name('organisers');
        Route::post('/organisers/invite', 'invite')->middleware('perm:organiser.manage')->name('invite');
        Route::post('/organisers/{orguser}', 'user')->middleware('perm:organiser.manage')->name('user');
        Route::get('/requests', 'requests')->middleware('perm:organiser.view')->name('requests');
        Route::post('/requests/{orgreq}', 'decide')->middleware('perm:organiser.approve')->name('decide');
        Route::get('/activity', 'activity')->middleware('perm:organiser.view')->name('activity');
        Route::get('/settings', 'settings')->middleware('perm:organiser.manage')->name('settings');
        Route::post('/settings', 'saveSettings')->middleware('perm:organiser.manage')->name('settings.save');
    });

    Route::controller(Controllers\InboxController::class)->prefix('inbox')->name('inbox.')->group(function () {
        Route::middleware('perm:inbox.view,inbox.reply,inbox.broadcast,inbox.manage')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/poll', 'poll')->name('poll');
            Route::get('/dashboard', 'dashboard')->name('dashboard');
            Route::get('/c/{conversation}', 'show')->whereNumber('conversation')->name('show');
            Route::get('/broadcasts', 'broadcasts')->name('broadcasts');
            Route::get('/broadcasts/{broadcast}', 'broadcast')->whereNumber('broadcast')->name('broadcast');
        });
        Route::middleware('perm:inbox.reply,inbox.manage')->group(function () {
            Route::post('/start', 'start')->name('start');
            Route::get('/guests', 'guestSearch')->name('guests');
            Route::post('/c/{conversation}/reply', 'reply')->whereNumber('conversation')->middleware('throttle:60,1')->name('reply');
            Route::post('/c/{conversation}/assign', 'assign')->whereNumber('conversation')->name('assign');
            Route::post('/c/{conversation}/department', 'department')->whereNumber('conversation')->name('department');
            Route::post('/c/{conversation}/priority', 'priority')->whereNumber('conversation')->name('priority');
            Route::post('/c/{conversation}/snooze', 'snooze')->whereNumber('conversation')->name('snooze');
            Route::post('/c/{conversation}/close', 'close')->whereNumber('conversation')->name('close');
            Route::post('/c/{conversation}/reopen', 'reopen')->whereNumber('conversation')->name('reopen');
            Route::post('/c/{conversation}/request', 'raise')->whereNumber('conversation')->name('request');
            Route::post('/c/{conversation}/link', 'link')->whereNumber('conversation')->name('link');
        });
        Route::middleware('perm:inbox.broadcast,inbox.manage')->group(function () {
            Route::get('/broadcasts/preview', 'broadcastPreview')->name('broadcasts.preview');
            Route::post('/broadcasts', 'broadcastStore')->middleware('throttle:10,1')->name('broadcasts.store');
        });
        Route::middleware('perm:inbox.manage')->group(function () {
            Route::get('/quick-replies', 'replies')->name('replies');
            Route::post('/quick-replies', 'replyStore')->name('replies.store');
            Route::put('/quick-replies/{quickreply}', 'replyUpdate')->whereNumber('quickreply')->name('replies.update');
            Route::delete('/quick-replies/{quickreply}', 'replyDestroy')->whereNumber('quickreply')->name('replies.destroy');
            Route::get('/rules', 'rules')->name('rules');
            Route::post('/rules', 'ruleStore')->name('rules.store');
            Route::post('/rules/test', 'ruleTest')->name('rules.test');
            Route::put('/rules/{inboxrule}', 'ruleUpdate')->whereNumber('inboxrule')->name('rules.update');
            Route::delete('/rules/{inboxrule}', 'ruleDestroy')->whereNumber('inboxrule')->name('rules.destroy');
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'saveSettings')->name('settings.save');
            Route::post('/settings/secret', 'secret')->name('settings.secret');
        });
    });
    // v7.2 self-service kiosk: dashboard & assistance queue, kiosks & pairing, guest visits, settings
    Route::controller(Controllers\KioskAdminController::class)->prefix('self-service')->name('kiosks.')->group(function () {
        Route::middleware('perm:kiosk.view,kiosk.assist,kiosk.manage')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/kiosks', 'kiosks')->name('devices');
            Route::get('/visits', 'sessions')->name('sessions');
            Route::get('/visits/{ksession}', 'session')->whereNumber('ksession')->name('session');
        });
        Route::middleware('perm:kiosk.assist,kiosk.manage')->group(function () {
            Route::post('/visits/{ksession}/resolve', 'resolve')->whereNumber('ksession')->name('resolve');
        });
        Route::middleware('perm:kiosk.manage')->group(function () {
            Route::post('/kiosks', 'store')->name('store');
            Route::post('/kiosks/pair', 'pair')->name('pair');
            Route::put('/kiosks/{kiosk}', 'update')->whereNumber('kiosk')->name('update');
            Route::post('/kiosks/{kiosk}/rotate', 'rotate')->whereNumber('kiosk')->name('rotate');
            Route::post('/kiosks/{kiosk}/status', 'status')->whereNumber('kiosk')->name('status');
            Route::delete('/kiosks/{kiosk}', 'destroy')->whereNumber('kiosk')->name('destroy');
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'saveSettings')->name('settings.save');
        });
    });

    // v6.6 parking & valet: desk (entry / exit / plate lookup), valet board, tickets, vehicles, permits, zones & tariff
    Route::controller(Controllers\ParkingController::class)->prefix('parking')->name('parking.')->group(function () {
        Route::middleware('perm:parking.view,parking.operate,parking.validate,parking.manage')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/valet', 'valet')->name('valet');
            Route::get('/tickets', 'sessions')->name('sessions');
            Route::get('/tickets/{parkingsession}', 'session')->whereNumber('parkingsession')->name('session');
            Route::get('/tickets/{parkingsession}/print', 'ticket')->whereNumber('parkingsession')->name('ticket');
            Route::get('/vehicles', 'vehicles')->name('vehicles');
            Route::get('/permits', 'permits')->name('permits');
            Route::get('/zones', 'zones')->name('zones');
        });
        Route::post('/tickets/{parkingsession}/validate', 'validateTicket')->whereNumber('parkingsession')->middleware('perm:parking.validate,parking.operate,parking.manage')->name('validate');
        Route::middleware('perm:parking.operate,parking.manage')->group(function () {
            Route::post('/check-in', 'checkIn')->name('checkin');
            Route::post('/tickets/{parkingsession}/request', 'request')->whereNumber('parkingsession')->name('request');
            Route::post('/tickets/{parkingsession}/ready', 'ready')->whereNumber('parkingsession')->name('ready');
            Route::post('/tickets/{parkingsession}/repark', 'repark')->whereNumber('parkingsession')->name('repark');
            Route::post('/tickets/{parkingsession}/move', 'move')->whereNumber('parkingsession')->name('move');
            Route::post('/tickets/{parkingsession}/exit', 'checkOut')->whereNumber('parkingsession')->name('exit');
            Route::post('/vehicles/{parkingvehicle}', 'vehicleUpdate')->whereNumber('parkingvehicle')->name('vehicle.update');
        });
        Route::middleware('perm:parking.manage')->group(function () {
            Route::post('/tickets/{parkingsession}/cancel', 'cancel')->whereNumber('parkingsession')->name('cancel');
            Route::post('/post-nightly', 'postNightly')->name('nightly');
            Route::post('/vehicles/{parkingvehicle}/ban', 'vehicleBan')->whereNumber('parkingvehicle')->name('vehicle.ban');
            Route::post('/permits', 'permitSave')->name('permits.store');
            Route::post('/permits/{parkingpermit}', 'permitSave')->whereNumber('parkingpermit')->name('permits.update');
            Route::post('/permits/{parkingpermit}/revoke', 'permitRevoke')->whereNumber('parkingpermit')->name('permits.revoke');
            Route::post('/zones', 'zoneSave')->name('zones.store');
            Route::post('/zones/{parkingzone}', 'zoneSave')->whereNumber('parkingzone')->name('zones.update');
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'saveSettings')->name('settings.save');
        });
    });

    // v6.5 quality assurance & brand standards: standards library, checklists, audits / mystery guest, scoring, corrective actions
    Route::controller(Controllers\QaController::class)->prefix('qa')->name('qa.')->group(function () {
        Route::middleware('perm:qa.view,qa.audit,qa.manage')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/audits', 'audits')->name('audits');
            Route::get('/audits/{qaaudit}', 'audit')->whereNumber('qaaudit')->name('audit');
            Route::get('/audits/{qaaudit}/print', 'auditPrint')->whereNumber('qaaudit')->name('audit.print');
            Route::post('/audits/{qaaudit}/acknowledge', 'acknowledge')->whereNumber('qaaudit')->name('audit.acknowledge');
            Route::get('/actions', 'actions')->name('actions');
            Route::post('/actions/{qaaction}', 'actionUpdate')->whereNumber('qaaction')->name('action.update');
            Route::post('/actions/{qaaction}/done', 'actionDone')->whereNumber('qaaction')->name('action.done');
            Route::post('/actions/{qaaction}/ticket', 'actionTicket')->whereNumber('qaaction')->name('action.ticket');
            Route::get('/standards', 'standards')->name('standards');
            Route::get('/checklists', 'templates')->name('templates');
        });
        Route::middleware('perm:qa.audit,qa.manage')->group(function () {
            Route::post('/audits', 'schedule')->name('audits.schedule');
            Route::post('/audits/{qaaudit}/record', 'record')->whereNumber('qaaudit')->name('audit.record');
            Route::post('/actions', 'actionStore')->name('actions.store');
        });
        Route::middleware('perm:qa.manage')->group(function () {
            Route::post('/audits/{qaaudit}/cancel', 'cancel')->whereNumber('qaaudit')->name('audit.cancel');
            Route::post('/actions/{qaaction}/verify', 'actionVerify')->whereNumber('qaaction')->name('action.verify');
            Route::post('/actions/{qaaction}/cancel', 'actionCancel')->whereNumber('qaaction')->name('action.cancel');
            Route::post('/standards', 'standardSave')->name('standards.save');
            Route::post('/checklists', 'templateSave')->name('templates.save');
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'settingsSave')->name('settings.save');
        });
    });

    Route::controller(Controllers\BusinessDevController::class)->prefix('bd')->name('bd.')->group(function () {
        Route::middleware('perm:bd.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/compset', 'compset')->name('compset');
            Route::get('/competitors/{bdcompetitor}', 'competitor')->whereNumber('bdcompetitor')->name('competitor');
            Route::get('/market', 'market')->name('market');
            Route::get('/partners', 'partners')->name('partners');
            Route::get('/partners/{bdpartner}', 'partner')->whereNumber('bdpartner')->name('partner');
            Route::get('/initiatives', 'initiatives')->name('initiatives');
            Route::get('/initiatives/{bdinitiative}', 'initiative')->whereNumber('bdinitiative')->name('initiative');
            Route::get('/initiatives/{bdinitiative}/print', 'initiativePrint')->whereNumber('bdinitiative')->name('initiative.print');
        });
        Route::middleware('perm:bd.edit,bd.approve,bd.manage')->group(function () {
            Route::post('/shops', 'shopStore')->name('shops.store');
            Route::post('/shops/{bdshop}/delete', 'shopDelete')->whereNumber('bdshop')->name('shop.delete');
            Route::post('/benchmarks', 'benchmarkSave')->name('benchmarks.save');
            Route::post('/partners', 'partnerStore')->name('partners.store');
            Route::put('/partners/{bdpartner}', 'partnerUpdate')->whereNumber('bdpartner')->name('partner.update');
            Route::post('/partners/{bdpartner}/status', 'partnerStatus')->whereNumber('bdpartner')->name('partner.status');
            Route::post('/initiatives', 'initiativeStore')->name('initiatives.store');
            Route::put('/initiatives/{bdinitiative}', 'initiativeUpdate')->whereNumber('bdinitiative')->name('initiative.update');
            Route::post('/initiatives/{bdinitiative}/stage', 'initiativeStage')->whereNumber('bdinitiative')->name('initiative.stage');
            Route::post('/initiatives/{bdinitiative}/submit', 'initiativeSubmit')->whereNumber('bdinitiative')->name('initiative.submit');
            Route::post('/initiatives/{bdinitiative}/decide', 'initiativeDecide')->whereNumber('bdinitiative')->name('initiative.decide');
            Route::post('/initiatives/{bdinitiative}/milestones', 'milestoneStore')->whereNumber('bdinitiative')->name('milestones.store');
            Route::post('/milestones/{bdmilestone}/toggle', 'milestoneToggle')->whereNumber('bdmilestone')->name('milestone.toggle');
            Route::post('/milestones/{bdmilestone}/delete', 'milestoneDelete')->whereNumber('bdmilestone')->name('milestone.delete');
            Route::post('/initiatives/{bdinitiative}/results', 'resultSave')->whereNumber('bdinitiative')->name('initiative.results');
        });
        Route::middleware('perm:bd.manage')->group(function () {
            Route::post('/competitors', 'competitorStore')->name('competitors.store');
            Route::put('/competitors/{bdcompetitor}', 'competitorUpdate')->whereNumber('bdcompetitor')->name('competitor.update');
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'settingsSave')->name('settings.save');
        });
    });

    Route::controller(Controllers\DigitalMarketingController::class)->prefix('digital')->name('digital.')->group(function () {
        Route::middleware('perm:digital.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/calendar', 'calendar')->name('calendar');
            Route::get('/posts/{dmpost}', 'post')->whereNumber('dmpost')->name('post');
            Route::get('/campaigns', 'campaigns')->name('campaigns');
            Route::get('/campaigns/{dmcampaign}', 'campaign')->whereNumber('dmcampaign')->name('campaign');
            Route::get('/links', 'links')->name('links');
            Route::get('/links/{dmlink}', 'link')->whereNumber('dmlink')->name('link');
            Route::get('/links/{dmlink}/qr', 'qr')->whereNumber('dmlink')->name('link.qr');
            Route::get('/channels', 'channels')->name('channels');
            Route::get('/channels/{dmchannel}', 'channel')->whereNumber('dmchannel')->name('channel');
            Route::get('/influencers', 'influencers')->name('influencers');
            Route::get('/influencers/{dminfluencer}', 'influencer')->whereNumber('dminfluencer')->name('influencer');
        });
        Route::middleware('perm:digital.content,digital.approve,digital.manage')->group(function () {
            Route::post('/posts', 'postStore')->name('posts.store');
            Route::put('/posts/{dmpost}', 'postUpdate')->whereNumber('dmpost')->name('post.update');
            Route::post('/posts/{dmpost}/submit', 'postSubmit')->whereNumber('dmpost')->name('post.submit');
            Route::post('/posts/{dmpost}/review', 'postReview')->whereNumber('dmpost')->name('post.review');
            Route::post('/posts/{dmpost}/publish', 'postPublish')->whereNumber('dmpost')->name('post.publish');
            Route::post('/posts/{dmpost}/stats', 'postStats')->whereNumber('dmpost')->name('post.stats');
            Route::post('/posts/{dmpost}/cancel', 'postCancel')->whereNumber('dmpost')->name('post.cancel');
            Route::post('/links', 'linkStore')->name('links.store');
            Route::put('/links/{dmlink}', 'linkUpdate')->whereNumber('dmlink')->name('link.update');
            Route::post('/links/{dmlink}/toggle', 'linkToggle')->whereNumber('dmlink')->name('link.toggle');
            Route::post('/channels/{dmchannel}/stats', 'channelStats')->whereNumber('dmchannel')->name('channel.stats');
            Route::post('/channels/{dmchannel}/audit', 'channelAudit')->whereNumber('dmchannel')->name('channel.audit');
            Route::post('/influencers/{dminfluencer}/results', 'influencerResults')->whereNumber('dminfluencer')->name('influencer.results');
        });
        Route::middleware('perm:digital.manage')->group(function () {
            Route::post('/channels', 'channelStore')->name('channels.store');
            Route::put('/channels/{dmchannel}', 'channelUpdate')->whereNumber('dmchannel')->name('channel.update');
            Route::post('/campaigns', 'campaignStore')->name('campaigns.store');
            Route::put('/campaigns/{dmcampaign}', 'campaignUpdate')->whereNumber('dmcampaign')->name('campaign.update');
            Route::post('/campaigns/{dmcampaign}/status', 'campaignStatus')->whereNumber('dmcampaign')->name('campaign.status');
            Route::post('/campaigns/{dmcampaign}/results', 'adStat')->whereNumber('dmcampaign')->name('campaign.adstat');
            Route::post('/campaigns/{dmcampaign}/import', 'import')->whereNumber('dmcampaign')->name('campaign.import');
            Route::post('/ad-results/{dmadstat}/delete', 'adStatDelete')->whereNumber('dmadstat')->name('adstat.delete');
            Route::post('/influencers', 'influencerStore')->name('influencers.store');
            Route::put('/influencers/{dminfluencer}', 'influencerUpdate')->whereNumber('dminfluencer')->name('influencer.update');
            Route::post('/influencers/{dminfluencer}/status', 'influencerStatus')->whereNumber('dminfluencer')->name('influencer.status');
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'settingsSave')->name('settings.save');
        });
    });

    // ---- v6.1 Sales & account management: accounts, contacts, opportunity pipeline, sales diary, targets
    Route::controller(Controllers\SalesController::class)->prefix('sales')->name('sales.')->group(function () {
        Route::middleware('perm:sales.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/accounts', 'accounts')->name('accounts');
            Route::get('/accounts/{saccount}', 'account')->whereNumber('saccount')->name('account');
            Route::get('/opportunities', 'opportunities')->name('opportunities');
            Route::get('/opportunities/{opp}', 'opportunity')->whereNumber('opp')->name('opportunity');
            Route::get('/opportunities/{opp}/proposal', 'proposal')->whereNumber('opp')->name('proposal');
            Route::get('/activities', 'activities')->name('activities');
            Route::get('/targets', 'targets')->name('targets');
        });
        Route::middleware('perm:sales.edit,sales.manage')->group(function () {
            Route::post('/accounts', 'accountStore')->name('accounts.store');
            Route::put('/accounts/{saccount}', 'accountUpdate')->whereNumber('saccount')->name('account.update');
            Route::post('/accounts/{saccount}/contacts', 'contactStore')->whereNumber('saccount')->name('contacts.store');
            Route::put('/contacts/{scontact}', 'contactUpdate')->whereNumber('scontact')->name('contact.update');
            Route::post('/contacts/{scontact}/delete', 'contactDelete')->whereNumber('scontact')->name('contact.delete');
            Route::post('/opportunities', 'opportunityStore')->name('opportunities.store');
            Route::put('/opportunities/{opp}', 'opportunityUpdate')->whereNumber('opp')->name('opportunity.update');
            Route::post('/opportunities/{opp}/stage', 'stage')->whereNumber('opp')->name('opportunity.stage');
            Route::post('/opportunities/{opp}/reopen', 'reopen')->whereNumber('opp')->name('opportunity.reopen');
            Route::post('/opportunities/{opp}/link', 'link')->whereNumber('opp')->name('opportunity.link');
            Route::post('/opportunities/{opp}/contract', 'draftContract')->whereNumber('opp')->name('opportunity.contract');
            Route::post('/activities', 'activityStore')->name('activities.store');
            Route::put('/activities/{sact}', 'activityUpdate')->whereNumber('sact')->name('activity.update');
            Route::post('/activities/{sact}/complete', 'complete')->whereNumber('sact')->name('activity.complete');
            Route::post('/activities/{sact}/cancel', 'cancel')->whereNumber('sact')->name('activity.cancel');
        });
        Route::middleware('perm:sales.manage')->group(function () {
            Route::post('/targets', 'saveTargets')->name('targets.save');
            Route::get('/settings', 'settings')->name('settings');
            Route::post('/settings', 'saveSettings')->name('settings.save');
        });
    });

    // ---- v5.9 Concierge: activities & excursions sold as agent, departures, vendors, local guide, settlements
    Route::controller(Controllers\ConciergeController::class)->prefix('concierge')->name('concierge.')->group(function () {
        Route::middleware('perm:concierge.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/bookings', 'bookings')->name('bookings');
            Route::get('/bookings/{cbooking}', 'show')->whereNumber('cbooking')->name('booking');
            Route::get('/bookings/{cbooking}/voucher', 'voucher')->whereNumber('cbooking')->name('voucher');
            Route::get('/departures/{cdeparture}/manifest', 'manifest')->whereNumber('cdeparture')->name('manifest');
            Route::get('/slots', 'slots')->name('slots');
            Route::get('/catalogue', 'catalogue')->name('catalogue');
            Route::get('/guide', 'guide')->name('guide');
            Route::get('/guide/print', 'guidePrint')->name('guide.print');
            Route::get('/settlements', 'settlements')->name('settlements');
            Route::get('/settlements/{csettle}', 'settlement')->whereNumber('csettle')->name('settlement');
        });
        Route::middleware('perm:concierge.book')->group(function () {
            Route::get('/bookings/new', 'create')->name('create');
            Route::post('/bookings', 'store')->name('store');
            Route::put('/bookings/{cbooking}', 'update')->whereNumber('cbooking')->name('update');
            Route::post('/bookings/{cbooking}/confirm', 'confirm')->whereNumber('cbooking')->name('confirm');
            Route::post('/bookings/{cbooking}/complete', 'complete')->whereNumber('cbooking')->name('complete');
            Route::post('/bookings/{cbooking}/no-show', 'noShow')->whereNumber('cbooking')->name('noshow');
            Route::post('/bookings/{cbooking}/cancel', 'cancel')->whereNumber('cbooking')->name('cancel');
            Route::post('/bookings/{cbooking}/send', 'sendVoucher')->whereNumber('cbooking')->middleware('throttle:10,1')->name('send');
            Route::put('/departures/{cdeparture}', 'departureUpdate')->whereNumber('cdeparture')->name('departure.update');
            Route::post('/departures/{cdeparture}/cancel', 'departureCancel')->whereNumber('cdeparture')->name('departure.cancel');
        });
        Route::middleware('perm:concierge.settle')->group(function () {
            Route::post('/settlements', 'settle')->name('settle');
            Route::post('/settlements/{csettle}/void', 'settlementVoid')->whereNumber('csettle')->name('settlement.void');
        });
        Route::middleware('perm:concierge.manage')->group(function () {
            Route::post('/activities', 'activityStore')->name('activity.store');
            Route::put('/activities/{cactivity}', 'activityUpdate')->whereNumber('cactivity')->name('activity.update');
            Route::delete('/activities/{cactivity}', 'activityDelete')->whereNumber('cactivity')->name('activity.delete');
            Route::post('/vendors', 'vendorStore')->name('vendor.store');
            Route::put('/vendors/{cvendor}', 'vendorUpdate')->whereNumber('cvendor')->name('vendor.update');
            Route::delete('/vendors/{cvendor}', 'vendorDelete')->whereNumber('cvendor')->name('vendor.delete');
            Route::post('/guide', 'placeStore')->name('place.store');
            Route::put('/guide/{cplace}', 'placeUpdate')->whereNumber('cplace')->name('place.update');
            Route::delete('/guide/{cplace}', 'placeDelete')->whereNumber('cplace')->name('place.delete');
            Route::get('/settings', 'settings')->name('settings');
            Route::put('/settings', 'saveSettings')->name('settings.save');
        });
    });

    Route::controller(Controllers\OwnerController::class)->prefix('owner')->name('owner.')->group(function () {
        Route::middleware('perm:owner.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/usali', 'usali')->name('usali');
            Route::get('/usali/{dept}', 'dept')->where('dept', '[a-z_]+')->name('usali.dept');
            Route::get('/fees', 'fees')->name('fees');
            Route::get('/reserve', 'reserve')->name('reserve');
            Route::get('/capex/{capex}', 'capexShow')->whereNumber('capex')->name('capex.show');
            Route::get('/statements', 'statements')->name('statements');
            Route::get('/statements/{period}', 'statementShow')->where('period', '\d{4}-\d{2}')->name('statements.show');
        });
        Route::middleware('perm:owner.operate')->group(function () {
            Route::post('/fees/accrue', 'accrue')->name('fees.accrue');
            Route::post('/fees/{ofee}/reverse', 'reverseFee')->name('fees.reverse');
            Route::post('/fees/pay', 'pay')->name('fees.pay');
            Route::post('/reserve/entries', 'entry')->name('reserve.entry');
            Route::delete('/reserve/entries/{ffe}', 'deleteEntry')->name('reserve.entry.delete');
            Route::post('/capex', 'capexStore')->name('capex.store');
            Route::put('/capex/{capex}', 'capexUpdate')->name('capex.update');
            Route::post('/capex/{capex}/submit', 'capexSubmit')->name('capex.submit');
            Route::post('/capex/{capex}/close/{to}', 'capexClose')->where('to', 'completed|cancelled')->name('capex.close');
            Route::post('/capex/{capex}/assets', 'capexLink')->name('capex.link');
            Route::delete('/capex/{capex}/assets/{asset}', 'capexUnlink')->name('capex.unlink');
            Route::post('/statements', 'statementPrepare')->name('statements.prepare');
            Route::put('/statements/{ostmt}/commentary', 'statementUpdate')->name('statements.update');
            Route::post('/statements/{ostmt}/issue', 'statementIssue')->name('statements.issue');
            Route::post('/queries/{oquery}/reply', 'queryReply')->name('queries.reply');
        });
        Route::middleware('perm:owner.approve')->group(function () {
            Route::post('/capex/{capex}/approve', 'capexApprove')->name('capex.approve');
            Route::post('/capex/{capex}/reject', 'capexReject')->name('capex.reject');
            Route::post('/statements/{ostmt}/acknowledge', 'statementAck')->name('statements.ack');
            Route::post('/statements/{ostmt}/queries', 'queryStore')->name('queries.store');
        });
        Route::middleware('perm:owner.approve,owner.operate')->post('/queries/{oquery}/close', 'queryClose')->name('queries.close');
        Route::middleware('perm:owner.manage')->group(function () {
            Route::get('/setup', 'setup')->name('setup');
            Route::put('/setup/agreement', 'agreementSave')->name('setup.agreement');
            Route::put('/setup/mapping', 'mappingSave')->name('setup.mapping');
            Route::put('/setup/departments', 'deptMappingSave')->name('setup.departments');
        });
    });

    // v5.4 report automation – saved views, schedules, KPI alerts (defined before /reports/{report})
    Route::controller(Controllers\ReportAutomationController::class)->prefix('reports')->name('reports.')->group(function () {
        Route::middleware('perm:reports.view')->group(function () {
            Route::get('/library', 'library')->name('library');
            Route::post('/views', 'storeView')->name('views.store');
            Route::put('/views/{rview}', 'updateView')->whereNumber('rview')->name('views.update');
            Route::post('/views/{rview}/pin', 'pinView')->whereNumber('rview')->name('views.pin');
            Route::delete('/views/{rview}', 'destroyView')->whereNumber('rview')->name('views.destroy');
        });
        Route::middleware('perm:reports.schedule')->group(function () {
            Route::get('/schedules', 'schedules')->name('schedules');
            Route::post('/schedules', 'storeSchedule')->name('schedules.store');
            Route::put('/schedules/{rschedule}', 'updateSchedule')->whereNumber('rschedule')->name('schedules.update');
            Route::post('/schedules/{rschedule}/run', 'runSchedule')->whereNumber('rschedule')->name('schedules.run');
            Route::post('/schedules/{rschedule}/toggle', 'toggleSchedule')->whereNumber('rschedule')->name('schedules.toggle');
            Route::delete('/schedules/{rschedule}', 'destroySchedule')->whereNumber('rschedule')->name('schedules.destroy');
            Route::get('/deliveries/{rdelivery}/csv', 'deliveryCsv')->whereNumber('rdelivery')->name('deliveries.csv');
            Route::post('/schedules/settings', 'settings')->name('schedules.settings');
        });
        Route::middleware('perm:reports.alerts')->group(function () {
            Route::get('/alerts', 'alerts')->name('alerts');
            Route::post('/alerts', 'storeAlert')->name('alerts.store');
            Route::put('/alerts/{kpialert}', 'updateAlert')->whereNumber('kpialert')->name('alerts.update');
            Route::post('/alerts/{kpialert}/toggle', 'toggleAlert')->whereNumber('kpialert')->name('alerts.toggle');
            Route::delete('/alerts/{kpialert}', 'destroyAlert')->whereNumber('kpialert')->name('alerts.destroy');
            Route::post('/alerts/evaluate', 'evaluate')->name('alerts.evaluate');
            Route::post('/alerts/events/{kpievent}/ack', 'acknowledge')->whereNumber('kpievent')->name('alerts.ack');
        });
    });
    // v5.5 rate packages, meal plans & inclusions
    Route::controller(Controllers\PackageController::class)->prefix('packages')->name('packages.')->group(function () {
        Route::middleware('perm:packages.view')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/register', 'register')->name('register');
            Route::get('/catalogue', 'catalogue')->name('catalogue');
        });
        Route::middleware('perm:packages.redeem')->group(function () {
            Route::get('/desk', 'desk')->name('desk');
            Route::post('/desk', 'redeem')->name('redeem');
        });
        Route::post('/folios/{folio}/add', 'addOn')->middleware('perm:packages.sell')->name('addon');
        Route::middleware('perm:packages.manage')->group(function () {
            Route::post('/redemptions/{pkgredeem}/void', 'void')->whereNumber('pkgredeem')->name('void');
            Route::post('/catalogue', 'store')->name('store');
            Route::put('/catalogue/{pkgitem}', 'update')->whereNumber('pkgitem')->name('update');
            Route::post('/catalogue/{pkgitem}/toggle', 'toggle')->whereNumber('pkgitem')->name('toggle');
            Route::post('/inclusions', 'inclusions')->name('inclusions');
            Route::post('/settings', 'settings')->name('settings');
        });
    });

    Route::get('/reports', [Controllers\ReportController::class, 'index'])->middleware('perm:reports.view')->name('reports.index');
    Route::get('/reports/{report}', [Controllers\ReportController::class, 'show'])->middleware('perm:reports.view')->name('reports.show');

    // ---- Generic masters (config-driven CRUD)
    Route::prefix('masters/{master}')->name('masters.')->controller(Controllers\MasterController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{id}/edit', 'edit')->name('edit');
        Route::put('/{id}', 'update')->name('update');
        Route::delete('/{id}', 'destroy')->name('destroy');
    });

    // ---- Administration
    Route::middleware('perm:users.manage')->group(function () {
        Route::resource('users', Controllers\UserController::class)->except(['show', 'destroy']);
        Route::post('/users/{id}/two-factor/reset', [Controllers\Auth\TwoFactorController::class, 'reset'])->whereNumber('id')->name('users.2fa.reset');
        Route::resource('roles', Controllers\RoleController::class)->except(['show']);
    });
    Route::middleware('perm:settings.manage')->group(function () {
        Route::get('/settings', [Controllers\SettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [Controllers\SettingController::class, 'update'])->name('settings.update');
        Route::get('/settings/backup', [Controllers\SettingController::class, 'backup'])->name('settings.backup');
        Route::post('/settings/backup', [Controllers\SettingController::class, 'download'])->name('settings.backup.download');
        // ---- v7.35 Data migration & cutover
        Route::prefix('settings/migration')->name('migration.')->controller(Controllers\DataMigrationController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::get('/template/{dataset}', 'template')->name('template');
            Route::get('/{batch}', 'show')->whereNumber('batch')->name('show');
            Route::post('/{batch}/validate', 'validate')->whereNumber('batch')->name('validate');
            Route::post('/{batch}/apply', 'apply')->whereNumber('batch')->name('apply');
        });
        Route::post('/settings/properties', [Controllers\SettingController::class, 'storeHotel'])->name('settings.hotels.store');
        Route::get('/settings/messaging', [Controllers\MessageController::class, 'settings'])->name('messages.settings');
        Route::post('/settings/messaging', [Controllers\MessageController::class, 'saveSettings'])->name('messages.settings.save');
        Route::post('/settings/messaging/test', [Controllers\MessageController::class, 'test'])->middleware('throttle:10,1')->name('messages.test');
    });
    // ---- v5.0 Food Safety & HACCP
    Route::prefix('food-safety')->name('fs.')->controller(Controllers\FoodSafetyController::class)->group(function () {
        Route::get('/', 'index')->middleware('perm:foodsafety.view')->name('index');
        Route::get('/temperatures', 'temps')->middleware('perm:foodsafety.view')->name('temps');
        Route::post('/temperatures', 'record')->middleware('perm:foodsafety.record')->name('temps.store');
        Route::post('/temperatures/{fslog}/cooling', 'cooling')->middleware('perm:foodsafety.record')->name('temps.cooling');
        Route::post('/temperatures/{fslog}/review', 'review')->middleware('perm:foodsafety.manage')->name('temps.review');
        Route::post('/equipment', 'saveEquipment')->middleware('perm:foodsafety.manage')->name('equipment.store');
        Route::put('/equipment/{fsequip}', 'saveEquipment')->middleware('perm:foodsafety.manage')->name('equipment.update');
        Route::get('/checklists', 'checklists')->middleware('perm:foodsafety.view')->name('checklists');
        Route::post('/checklists', 'saveChecklist')->middleware('perm:foodsafety.manage')->name('checklists.store');
        Route::put('/checklists/{fslist}', 'saveChecklist')->middleware('perm:foodsafety.manage')->name('checklists.update');
        Route::get('/checklists/{fslist}/run', 'runForm')->middleware('perm:foodsafety.record')->name('checklists.run');
        Route::post('/checklists/{fslist}/run', 'complete')->middleware('perm:foodsafety.record')->name('checklists.complete');
        Route::get('/checklist-runs/{fsrun}', 'showRun')->middleware('perm:foodsafety.view')->name('checklists.show');
        Route::post('/checklist-runs/{fsrun}/sign', 'sign')->middleware('perm:foodsafety.manage')->name('checklists.sign');
        Route::get('/allergens', 'allergens')->middleware('perm:foodsafety.view')->name('allergens');
        Route::post('/allergens/menu', 'saveMenuAllergens')->middleware('perm:foodsafety.manage')->name('allergens.menu');
        Route::post('/allergens/ingredients', 'saveIngredientAllergens')->middleware('perm:foodsafety.manage')->name('allergens.ingredients');
        Route::get('/allergens/print', 'allergenPrint')->middleware('perm:foodsafety.view')->name('allergens.print');
        Route::get('/waste', 'waste')->middleware('perm:foodsafety.view')->name('waste');
        Route::post('/waste', 'storeWaste')->middleware('perm:foodsafety.record')->name('waste.store');
        Route::post('/waste/{fswaste}/cancel', 'cancelWaste')->middleware('perm:foodsafety.manage')->name('waste.cancel');
        Route::get('/certificates', 'certs')->middleware('perm:foodsafety.view')->name('certs');
        Route::post('/certificates', 'storeCert')->middleware('perm:foodsafety.certs')->name('certs.store');
        Route::get('/labels', 'labels')->middleware('perm:foodsafety.record')->name('labels');
        Route::post('/labels', 'storeLabels')->middleware('perm:foodsafety.record')->name('labels.store');
        Route::get('/labels/{fslabel}/print', 'printLabels')->middleware('perm:foodsafety.record')->name('labels.print');
        Route::post('/settings', 'settings')->middleware('perm:foodsafety.manage')->name('settings');
        Route::get('/inspection-pack', 'pack')->middleware('perm:foodsafety.view')->name('pack');
    });

    // ---- v5.1 Health Club & Memberships
    Route::prefix('club')->name('club.')->group(function () {
        Route::controller(Controllers\ClubController::class)->group(function () {
            Route::get('/', 'index')->middleware('perm:club.view')->name('index');
            Route::get('/desk', 'desk')->middleware('perm:club.desk')->name('desk');
            Route::post('/desk/check-in', 'checkIn')->middleware('perm:club.desk')->name('checkin');
            Route::post('/desk/guest', 'guest')->middleware('perm:club.desk')->name('guest');
            Route::post('/desk/resident', 'resident')->middleware('perm:club.desk')->name('resident');
            Route::post('/desk/ticket', 'ticket')->middleware('perm:club.desk')->name('ticket');
            Route::post('/visits/{clubvisit}/check-out', 'checkOut')->middleware('perm:club.desk')->name('checkout');
            Route::get('/visits', 'visits')->middleware('perm:club.view')->name('visits');
            Route::get('/members', 'members')->middleware('perm:club.view')->name('members');
            Route::get('/members/enrol', 'create')->middleware('perm:club.members')->name('members.create');
            Route::post('/members', 'store')->middleware('perm:club.members')->name('members.store');
            Route::get('/members/{membership}', 'show')->middleware('perm:club.view')->name('members.show');
            Route::put('/members/{membership}', 'update')->middleware('perm:club.members')->name('members.update');
            Route::post('/members/{membership}/persons', 'addPerson')->middleware('perm:club.members')->name('persons.store');
            Route::put('/persons/{clubmember}', 'updatePerson')->middleware('perm:club.members')->name('persons.update');
            Route::post('/persons/{clubmember}/photo', 'photo')->middleware('perm:club.members')->name('persons.photo');
            Route::get('/persons/{clubmember}/photo', 'photoShow')->middleware('perm:club.view')->name('persons.photo.show');
            Route::post('/persons/{clubmember}/new-card', 'newCard')->middleware('perm:club.members')->name('persons.card');
            Route::get('/members/{membership}/cards', 'cards')->middleware('perm:club.view')->name('members.cards');
            Route::post('/members/{membership}/freeze', 'freeze')->middleware('perm:club.members')->name('members.freeze');
            Route::post('/members/{membership}/unfreeze', 'unfreeze')->middleware('perm:club.members')->name('members.unfreeze');
            Route::post('/members/{membership}/cancel', 'cancel')->middleware('perm:club.manage')->name('members.cancel');
            Route::post('/members/{membership}/withdraw-notice', 'withdrawNotice')->middleware('perm:club.members')->name('members.withdraw');
            Route::get('/passes', 'passes')->middleware('perm:club.view')->name('passes');
            Route::post('/passes', 'sellPass')->middleware('perm:club.desk')->name('passes.store');
            Route::post('/passes/{daypass}/cancel', 'cancelPass')->middleware('perm:club.desk')->name('passes.cancel');
            Route::get('/plans', 'plans')->middleware('perm:club.view')->name('plans');
            Route::post('/plans', 'savePlan')->middleware('perm:club.manage')->name('plans.store');
            Route::put('/plans/{clubplan}', 'savePlan')->middleware('perm:club.manage')->name('plans.update');
            Route::post('/settings', 'settings')->middleware('perm:club.manage')->name('settings');
            Route::post('/nightly', 'nightly')->middleware('perm:club.manage')->name('nightly');
        });
        Route::controller(Controllers\ClubBillingController::class)->group(function () {
            Route::get('/billing', 'index')->middleware('perm:club.view')->name('billing');
            Route::post('/billing/renewal-run', 'renewalRun')->middleware('perm:club.members')->name('billing.run');
            Route::post('/members/{membership}/renew', 'renew')->middleware('perm:club.members')->name('members.renew');
            Route::post('/members/{membership}/pay', 'pay')->middleware('perm:club.members')->name('members.pay');
            Route::post('/members/{membership}/refund', 'refund')->middleware('perm:club.manage')->name('members.refund');
            Route::post('/members/{membership}/charge', 'charge')->middleware('perm:club.members')->name('members.charge');
            Route::get('/members/{membership}/statement', 'statement')->middleware('perm:club.view')->name('members.statement');
            Route::post('/charges/{clubcharge}/cancel', 'cancelCharge')->middleware('perm:club.manage')->name('charges.cancel');
            Route::post('/charges/{clubcharge}/credit', 'credit')->middleware('perm:club.manage')->name('charges.credit');
            Route::get('/charges/{clubcharge}/invoice', 'invoice')->middleware('perm:club.view')->name('charges.invoice');
            Route::get('/receipts/{clubpay}', 'receipt')->middleware('perm:club.view')->name('receipts.show');
            Route::post('/receipts/{clubpay}/void', 'voidReceipt')->middleware('perm:club.manage')->name('receipts.void');
        });
        Route::controller(Controllers\ClubClassController::class)->group(function () {
            Route::get('/classes', 'index')->middleware('perm:club.view')->name('classes');
            Route::post('/classes', 'save')->middleware('perm:club.manage')->name('classes.store');
            Route::put('/classes/{clubclass}', 'save')->middleware('perm:club.manage')->name('classes.update');
            Route::get('/classes/{clubclass}/{date}', 'session')->middleware('perm:club.view')->where('date', '\d{4}-\d{2}-\d{2}')->name('classes.session');
            Route::post('/classes/{clubclass}/{date}/book', 'book')->middleware('perm:club.desk')->where('date', '\d{4}-\d{2}-\d{2}')->name('classes.book');
            Route::post('/classes/{clubclass}/{date}/attendance', 'attendance')->middleware('perm:club.desk')->where('date', '\d{4}-\d{2}-\d{2}')->name('classes.attendance');
            Route::post('/bookings/{clubbooking}/cancel', 'cancel')->middleware('perm:club.desk')->name('bookings.cancel');
        });
    });

    // ---- v7.38 Recreation ticketing (POS-style outlet, Wellness & Recreation)
    Route::prefix('recreation')->name('recreation.')->group(function () {
        Route::controller(Controllers\RecreationController::class)->group(function () {
            Route::get('/outlet', 'outlet')->middleware('perm:club.desk')->name('outlet');
            Route::post('/outlet/sell', 'sell')->middleware('perm:club.desk')->name('sell');
            Route::get('/sales', 'sales')->middleware('perm:club.view')->name('sales');
            Route::get('/sales/{sale}/receipt', 'receipt')->middleware('perm:club.view')->name('receipt');
            Route::post('/sales/{sale}/void', 'void')->middleware('perm:club.manage')->name('void');
            Route::get('/types', 'types')->middleware('perm:club.manage')->name('types');
            Route::post('/types', 'saveType')->middleware('perm:club.manage')->name('types.store');
            Route::put('/types/{type}', 'saveType')->middleware('perm:club.manage')->name('types.update');
        });
    });

    // ---- v5.3 Income Audit & Revenue Integrity
    Route::prefix('income-audit')->name('audit.')->group(function () {
        Route::controller(Controllers\IncomeAuditController::class)->group(function () {
            Route::get('/', 'index')->middleware('perm:income_audit.view')->name('index');
            Route::post('/start', 'start')->middleware('perm:income_audit.audit')->name('start');
            Route::post('/settings', 'settings')->middleware('perm:income_audit.close')->name('settings');
            Route::get('/days/{auditday}', 'show')->middleware('perm:income_audit.view')->name('day');
            Route::get('/days/{auditday}/print', 'print')->middleware('perm:income_audit.view')->name('print');
            Route::post('/days/{auditday}/run', 'run')->middleware('perm:income_audit.audit')->name('run');
            Route::post('/days/{auditday}/sign', 'sign')->middleware('perm:income_audit.audit')->name('sign');
            Route::post('/days/{auditday}/close', 'close')->middleware('perm:income_audit.close')->name('close');
            Route::post('/days/{auditday}/reopen', 'reopen')->middleware('perm:income_audit.close')->name('reopen');
            Route::post('/findings/{auditfinding}', 'resolve')->middleware('perm:income_audit.audit,income_audit.close')->name('resolve');
            Route::get('/room-count', 'count')->middleware('perm:income_audit.count')->name('count');
            Route::post('/room-count', 'saveCount')->middleware('perm:income_audit.count')->name('count.save');
            Route::get('/discrepancies', 'discrepancies')->middleware('perm:income_audit.view,income_audit.authorise')->name('discrepancies');
            Route::post('/room-count/{roomcount}/resolve', 'resolveCount')->middleware('perm:income_audit.authorise,income_audit.audit')->name('count.resolve');
        });
        Route::controller(Controllers\AuthorisationController::class)->group(function () {
            Route::get('/authorisations', 'index')->middleware('perm:income_audit.request,income_audit.view')->name('auth');
            Route::post('/authorisations', 'store')->middleware('perm:income_audit.request')->name('auth.store');
            Route::post('/authorisations/{revauth}/approve', 'approve')->middleware('perm:income_audit.authorise,income_audit.close')->name('auth.approve');
            Route::post('/authorisations/{revauth}/decline', 'decline')->middleware('perm:income_audit.authorise,income_audit.close')->name('auth.decline');
            Route::post('/authorisations/{revauth}/cancel', 'cancel')->middleware('perm:income_audit.request')->name('auth.cancel');
        });
    });

    // ---- v5.2 Serviced Apartments & Long-Stay Leases
    Route::prefix('leases')->name('leases.')->group(function () {
        Route::controller(Controllers\LeaseBillingController::class)->group(function () {
            Route::get('/billing', 'index')->middleware('perm:leases.view')->name('billing');
            Route::post('/billing/run', 'run')->middleware('perm:leases.billing')->name('billing.run');
            Route::post('/charges/{leasecharge}/cancel', 'cancelCharge')->middleware('perm:leases.approve')->name('charges.cancel');
            Route::post('/charges/{leasecharge}/credit', 'credit')->middleware('perm:leases.approve')->name('charges.credit');
            Route::get('/charges/{leasecharge}/invoice', 'invoice')->middleware('perm:leases.view')->name('charges.invoice');
            Route::get('/receipts/{leasepay}', 'receipt')->middleware('perm:leases.view')->name('receipts.show');
            Route::post('/receipts/{leasepay}/void', 'voidReceipt')->middleware('perm:leases.approve')->name('receipts.void');
            Route::post('/{lease}/pay', 'pay')->middleware('perm:leases.billing')->name('pay');
            Route::post('/{lease}/refund', 'refund')->middleware('perm:leases.approve')->name('refund');
            Route::post('/{lease}/apply-deposit', 'applyDeposit')->middleware('perm:leases.approve')->name('apply');
            Route::post('/{lease}/charge', 'charge')->middleware('perm:leases.billing')->name('charge');
            Route::get('/{lease}/statement', 'statement')->middleware('perm:leases.view')->name('statement');
            Route::get('/{lease}/settlement', 'settlement')->middleware('perm:leases.view')->name('settlement');
        });
        Route::controller(Controllers\LeaseController::class)->group(function () {
            Route::get('/', 'index')->middleware('perm:leases.view')->name('index');
            Route::get('/all', 'list')->middleware('perm:leases.view')->name('list');
            Route::get('/units', 'units')->middleware('perm:leases.view')->name('units');
            Route::post('/units', 'saveUnit')->middleware('perm:leases.manage')->name('units.store');
            Route::put('/units/{leaseunit}', 'saveUnit')->middleware('perm:leases.manage')->name('units.update');
            Route::post('/settings', 'settings')->middleware('perm:leases.approve')->name('settings');
            Route::get('/meters', 'meters')->middleware('perm:leases.view')->name('meters');
            Route::post('/meters', 'saveReadings')->middleware('perm:leases.manage')->name('meters.store');
            Route::get('/inspections/{leaseinspection}', 'inspection')->middleware('perm:leases.view')->name('inspections.show');
            Route::get('/new', 'create')->middleware('perm:leases.manage')->name('create');
            Route::post('/', 'store')->middleware('perm:leases.manage')->name('store');
            Route::get('/{lease}', 'show')->middleware('perm:leases.view')->name('show');
            Route::get('/{lease}/edit', 'edit')->middleware('perm:leases.manage')->name('edit');
            Route::put('/{lease}', 'update')->middleware('perm:leases.manage')->name('update');
            Route::get('/{lease}/agreement', 'agreement')->middleware('perm:leases.view')->name('agreement');
            Route::post('/{lease}/sign', 'sign')->middleware('perm:leases.manage')->name('sign');
            Route::post('/{lease}/move-in', 'activate')->middleware('perm:leases.manage')->name('activate');
            Route::post('/{lease}/cancel', 'cancel')->middleware('perm:leases.manage')->name('cancel');
            Route::post('/{lease}/notice', 'notice')->middleware('perm:leases.manage')->name('notice');
            Route::post('/{lease}/withdraw-notice', 'withdrawNotice')->middleware('perm:leases.manage')->name('withdraw');
            Route::post('/{lease}/renew', 'renew')->middleware('perm:leases.manage')->name('renew');
            Route::post('/{lease}/move-out', 'moveOut')->middleware('perm:leases.manage')->name('moveout');
            Route::post('/{lease}/settle', 'settle')->middleware('perm:leases.approve')->name('settle');
            Route::post('/{lease}/inspections', 'inspect')->middleware('perm:leases.manage')->name('inspect');
        });
    });

    Route::get('/email-log', [Controllers\EmailController::class, 'index'])->middleware('perm:logs.view')->name('emails.index');
    Route::get('/email-log/preview/{template}', [Controllers\EmailController::class, 'preview'])->middleware('perm:logs.view')->name('emails.preview');
    Route::get('/message-log', [Controllers\MessageController::class, 'index'])->middleware('perm:logs.view')->name('messages.index');
    Route::get('/activity-log', [Controllers\ActivityLogController::class, 'index'])->middleware('perm:logs.view')->name('logs.index');
});
