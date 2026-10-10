<?php

namespace App\Http\Controllers;

use App\Models\ChannelConnection;
use App\Models\Hotel;
use App\Services\ChannelService;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * v2.8 channel API (stateless, per-connection token in the path, HMAC-SHA256 signed with the connection's shared secret):
 *  POST /api/channel/{token}/reservations – new / modify / cancel (signature over "{timestamp}.{raw body}")
 *  GET  /api/channel/{token}/ari?from=Y-m-d&days=N – pull availability & rates (signature over "{timestamp}.from={from}&days={days}")
 * Headers: X-Nowills-Timestamp (unix seconds, ±5 min) and X-Nowills-Signature (sha256=<hex>).
 */
class ChannelApiController extends Controller
{
    protected function connection(string $token): ?ChannelConnection
    {
        $c = ChannelConnection::withoutGlobalScope('hotel')->where('token', $token)->first();
        $hotel = $c ? Hotel::where('is_active', true)->find($c->hotel_id) : null;
        if (! $hotel) {
            return null;
        }
        Tenant::set($hotel);

        return $c;
    }

    protected function error(string $msg, int $code): JsonResponse
    {
        return response()->json(['status' => 'error', 'error' => $msg], $code);
    }

    public function reservations(Request $request, string $token): JsonResponse
    {
        $c = $this->connection($token);
        if (! $c) {
            return $this->error('Unknown channel endpoint.', 404);
        }
        $raw = (string) $request->getContent();
        if (! ChannelService::verify($c, $request->header('X-Nowills-Timestamp'), $request->header('X-Nowills-Signature'), $raw)) {
            return $this->error('Invalid or expired signature.', 401);
        }
        if (! $c->is_active) {
            return $this->error('This connection is paused at the hotel – retry later.', 423);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return $this->error('Body must be a JSON object.', 400);
        }
        [$code, $body] = ChannelService::receive($c, $payload);

        return response()->json($body, $code);
    }

    public function ari(Request $request, string $token): JsonResponse
    {
        $c = $this->connection($token);
        if (! $c) {
            return $this->error('Unknown channel endpoint.', 404);
        }
        $from = (string) $request->query('from', bdate()->toDateString());
        $days = (int) $request->query('days', 30);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || $days < 1 || $days > 400) {
            return $this->error('from must be Y-m-d and days 1–400.', 422);
        }
        if (! ChannelService::verify($c, $request->header('X-Nowills-Timestamp'), $request->header('X-Nowills-Signature'), "from={$from}&days={$days}")) {
            return $this->error('Invalid or expired signature.', 401);
        }
        if (! $c->is_active) {
            return $this->error('This connection is paused at the hotel.', 423);
        }
        $start = Carbon::parse($from)->max(bdate()->copy()->startOfDay());
        $ari = ChannelService::ari($c, $start, $days);

        return response()->json(ChannelService::payload($c, $ari, 'pull'));
    }
}
