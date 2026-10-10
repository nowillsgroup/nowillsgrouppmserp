<?php

namespace App\Http\Controllers;

use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use App\Models\BusinessSource;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\ApiService;
use App\Services\WebhookService;
use App\Support\ApiSpec;
use App\Support\Tenant;
use Illuminate\Http\Request;
use RuntimeException;

/** v4.0 – Administration › API & Webhooks: keys, webhook endpoints, deliveries, request log, reference docs, settings. */
class DeveloperController extends Controller
{
    public const TABS = ['clients' => 'API keys', 'webhooks' => 'Webhooks', 'deliveries' => 'Deliveries', 'log' => 'Request log', 'docs' => 'Reference', 'settings' => 'Settings'];

    protected function guarded(callable $fn)
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'clients';
        if ($tab === 'settings' && ! can_do('api.manage')) {
            $tab = 'clients';
        }
        $day = ApiRequestLog::where('created_at', '>=', now()->subDay());
        $v = ['tab' => $tab, 'cfg' => ApiService::cfg(), 'wh' => WebhookService::stats(),
            'snap' => ['clients' => ApiClient::whereNull('revoked_at')->where('is_active', true)->count(), 'requests' => (clone $day)->count(),
                'errors' => (clone $day)->where('status', '>=', 400)->count(), 'endpoints' => WebhookEndpoint::where('is_active', true)->count()]];
        if (can_do('api.manage')) {   // the "New API key" dialog is on every tab
            $v += ['owners' => User::where('hotel_id', Tenant::id())->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
                'sources' => BusinessSource::where('is_active', true)->orderBy('name')->pluck('name', 'id')];
        }
        if ($tab === 'clients') {
            $v += ['clients' => ApiClient::with('owner', 'creator')->orderByRaw('revoked_at IS NOT NULL')->orderBy('name')->get(), 'usage' => ApiService::usage()];
        } elseif ($tab === 'webhooks') {
            $v += ['endpoints' => WebhookEndpoint::orderBy('name')->get(), 'health' => WebhookService::health()];
        } elseif ($tab === 'deliveries') {
            $v += ['deliveries' => WebhookDelivery::with('endpoint')
                ->when($request->query('endpoint'), fn ($q, $e) => $q->where('webhook_endpoint_id', $e))
                ->when(array_key_exists((string) $request->query('status'), WebhookDelivery::STATUSES), fn ($q) => $q->where('status', $request->query('status')))
                ->when($request->query('event'), fn ($q, $e) => $q->where('event', $e))
                ->orderByDesc('id')->paginate(30)->withQueryString(), 'endpoints' => WebhookEndpoint::orderBy('name')->pluck('name', 'id')];
        } elseif ($tab === 'log') {
            $cls = $request->query('result');
            $v += ['logs' => ApiRequestLog::with('client')
                ->when($request->query('client'), fn ($q, $c) => $q->where('api_client_id', $c))
                ->when($cls === 'ok', fn ($q) => $q->where('status', '<', 400))->when($cls === 'client', fn ($q) => $q->whereBetween('status', [400, 499]))
                ->when($cls === 'server', fn ($q) => $q->where('status', '>=', 500))
                ->when($request->query('route'), fn ($q, $r) => $q->where('route', $r))
                ->orderByDesc('id')->paginate(50)->withQueryString(),
                'clients' => ApiClient::orderBy('name')->pluck('name', 'id'),
                'routes' => (clone $day)->whereNotNull('route')->groupBy('route')->selectRaw('route, count(*) n, sum(status >= 400) errors, round(avg(duration_ms)) ms')
                    ->orderByDesc('n')->limit(8)->get(),
                'refused' => ApiRequestLog::withoutGlobalScope('hotel')->whereNull('hotel_id')->where('created_at', '>=', now()->subDay())->count()];
        } elseif ($tab === 'docs') {
            $v += ['endpoints' => ApiSpec::endpoints()];
        }

        return view('developers.index', $v);
    }

    public function delivery(WebhookDelivery $delivery)
    {
        return view('developers.delivery', ['d' => $delivery->load('endpoint')]);
    }

    // ───────────────────────────── API keys ─────────────────────────────

    protected function clientData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:80', 'description' => 'nullable|string|max:255', 'scopes' => 'required|array', 'scopes.*' => 'string',
            'ip_allowlist' => 'nullable|string|max:2000', 'rate_limit' => 'nullable|integer|min:10|max:6000', 'expires_on' => 'nullable|date',
            'owner_id' => 'nullable|integer', 'business_source_id' => 'nullable|integer|owned:business_sources', 'is_active' => 'nullable|boolean',
        ], ['scopes.required' => 'Choose at least one permission (scope) for the key.']);
    }

    public function storeClient(Request $request)
    {
        return $this->guarded(function () use ($request) {
            [$c, $token] = ApiService::createClient($this->clientData($request));

            return redirect()->route('developers.index', ['tab' => 'clients'])->with('success', "API key for \"{$c->name}\" created.")
                ->with('api_secret', ['kind' => 'token', 'label' => $c->name, 'value' => $token]);
        });
    }

    public function updateClient(Request $request, ApiClient $apiclient)
    {
        return $this->guarded(function () use ($request, $apiclient) {
            ApiService::updateClient($apiclient, $this->clientData($request) + ['is_active' => $request->boolean('is_active')]);

            return redirect()->route('developers.index', ['tab' => 'clients'])->with('success', "API client \"{$apiclient->name}\" saved.");
        });
    }

    public function rotateClient(ApiClient $apiclient)
    {
        return $this->guarded(function () use ($apiclient) {
            $token = ApiService::rotate($apiclient);

            return redirect()->route('developers.index', ['tab' => 'clients'])->with('success', "New key issued for \"{$apiclient->name}\" – the old key stopped working.")
                ->with('api_secret', ['kind' => 'token', 'label' => $apiclient->name, 'value' => $token]);
        });
    }

    public function revokeClient(Request $request, ApiClient $apiclient)
    {
        $d = $request->validate(['reason' => 'required|string|min:3|max:190']);
        ApiService::revoke($apiclient, $d['reason']);

        return redirect()->route('developers.index', ['tab' => 'clients'])->with('success', "API key of \"{$apiclient->name}\" revoked.");
    }

    // ───────────────────────────── webhooks ─────────────────────────────

    protected function hookData(Request $request): array
    {
        return $request->validate(['name' => 'required|string|max:80', 'url' => 'required|string|max:500', 'events' => 'required|array', 'events.*' => 'string',
            'description' => 'nullable|string|max:255'], ['events.required' => 'Subscribe the endpoint to at least one event.']);
    }

    public function storeWebhook(Request $request)
    {
        return $this->guarded(function () use ($request) {
            [$e, $secret] = WebhookService::create($this->hookData($request));

            return redirect()->route('developers.index', ['tab' => 'webhooks'])->with('success', "Webhook \"{$e->name}\" added – send a test event to check it.")
                ->with('api_secret', ['kind' => 'secret', 'label' => $e->name, 'value' => $secret]);
        });
    }

    public function updateWebhook(Request $request, WebhookEndpoint $webhook)
    {
        return $this->guarded(function () use ($request, $webhook) {
            WebhookService::update($webhook, $this->hookData($request));

            return redirect()->route('developers.index', ['tab' => 'webhooks'])->with('success', "Webhook \"{$webhook->name}\" saved.");
        });
    }

    public function rotateWebhook(WebhookEndpoint $webhook)
    {
        $secret = WebhookService::rotateSecret($webhook);

        return redirect()->route('developers.index', ['tab' => 'webhooks'])->with('success', "Signing secret of \"{$webhook->name}\" rotated – the old secret keeps signing for 24 hours.")
            ->with('api_secret', ['kind' => 'secret', 'label' => $webhook->name, 'value' => $secret]);
    }

    public function toggleWebhook(WebhookEndpoint $webhook)
    {
        WebhookService::setActive($webhook, ! $webhook->is_active);

        return back()->with('success', "Webhook \"{$webhook->name}\" ".($webhook->is_active ? 'enabled – pending events will be delivered.' : 'paused.'));
    }

    public function pingWebhook(WebhookEndpoint $webhook)
    {
        $d = WebhookService::ping($webhook);

        return back()->with($d->status === 'success' ? 'success' : 'error', $d->status === 'success'
            ? "Test event delivered to {$webhook->host()} (HTTP {$d->response_code}, {$d->duration_ms} ms)."
            : "Test event to {$webhook->host()} failed: {$d->error}. It will be retried automatically.");
    }

    public function deleteWebhook(WebhookEndpoint $webhook)
    {
        WebhookService::delete($webhook);

        return redirect()->route('developers.index', ['tab' => 'webhooks'])->with('success', 'Webhook deleted.');
    }

    public function retryDelivery(WebhookDelivery $delivery)
    {
        return $this->guarded(function () use ($delivery) {
            $d = WebhookService::retry($delivery);

            return back()->with($d->status === 'success' ? 'success' : 'error', $d->status === 'success'
                ? "Delivered (HTTP {$d->response_code})." : "Still failing: {$d->error}.".($d->status === 'pending' ? ' Next automatic attempt '.$d->next_attempt_at->diffForHumans().'.' : ''));
        });
    }

    public function deliverNow()
    {
        [$n, $ok, $bad] = WebhookService::deliverDue(Tenant::id());

        return back()->with($bad ? 'warning' : 'success', $n ? "{$n} due deliver(ies) attempted: {$ok} delivered, {$bad} failed." : 'Nothing is due for delivery right now.');
    }

    public function settings(Request $request)
    {
        $d = $request->validate(['enabled' => 'nullable|boolean', 'log_days' => 'required|integer|min:1|max:365', 'allow_http' => 'nullable|boolean',
            'allow_private' => 'nullable|boolean', 'disable_after' => 'required|integer|min:3|max:500']);
        ApiService::saveSettings($d);

        return redirect()->route('developers.index', ['tab' => 'settings'])->with('success', 'API & webhook settings saved.');
    }
}
