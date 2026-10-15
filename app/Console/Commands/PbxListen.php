<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\ActivityLogger;
use App\Services\CallAccountingService;
use App\Services\InterfaceService;
use App\Support\Tenant;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * v7.36 – live SMDR capture: stream call-detail records from the PABX.
 *
 *   pms:pbx:listen --hotel=1                       # dial into the PBX SMDR port (connect mode)
 *   pms:pbx:listen --hotel=1 --mode=listen --port=4456   # the PBX connects to us
 *   pms:pbx:listen --hotel=1 --once                # one session (for testing / cron wrappers)
 *
 * Lines are parsed with the configured SMDR column order (Interfaces › PABX) and
 * flow through CallAccountingService, so rating, extension mapping, auto-post and
 * duplicate detection behave exactly like a manual import or the HTTP push.
 */
class PbxListen extends Command
{
    protected $signature = 'pms:pbx:listen
        {--hotel= : Hotel (property) id to capture for}
        {--host= : SMDR source host (default: the pbx_smdr_host setting)}
        {--port= : SMDR source port (default: the pbx_smdr_port setting)}
        {--mode=connect : connect (the PMS dials the PBX) or listen (the PBX dials the PMS)}
        {--flush=5 : seconds between import flushes}
        {--once : capture one stream session, then exit}';

    protected $description = 'Stream SMDR call records live from the PABX (call accounting)';

    public function handle(): int
    {
        $hotel = $this->resolveHotel();
        if (! $hotel) {
            return self::FAILURE;
        }
        Tenant::set($hotel);
        $cfg = InterfaceService::cfg();
        if ($cfg['pbx_enabled'] !== '1') {
            $this->error('The PABX interface is switched off for '.$hotel->name.' (Interfaces › PABX).');

            return self::FAILURE;
        }

        $mode = $this->option('mode') === 'listen' ? 'listen' : 'connect';
        $host = trim((string) ($this->option('host') ?: $cfg['pbx_smdr_host']));
        $port = (int) ($this->option('port') ?: $cfg['pbx_smdr_port']);
        $once = (bool) $this->option('once');
        $flushSecs = max(1, (int) $this->option('flush'));

        if ($port < 1 || $port > 65535) {
            $this->error('Set the SMDR port (Interfaces › PABX or --port=).');

            return self::FAILURE;
        }
        if ($mode === 'connect' && $host === '') {
            $this->error('Set the SMDR host (Interfaces › PABX or --host=) for connect mode.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Capturing SMDR for %s — %s %s:%d (flush every %ds%s)',
            $hotel->name,
            $mode,
            $mode === 'listen' ? ($host !== '' ? $host : '0.0.0.0') : $host,
            $port,
            $flushSecs,
            $once ? ', one session' : ', Ctrl+C to stop',
        ));

        $backoff = 2;
        while (true) {
            try {
                $fp = $mode === 'listen' ? $this->accept($host !== '' ? $host : '0.0.0.0', $port) : $this->connect($host, $port);
            } catch (RuntimeException $e) {
                $this->warn($e->getMessage());
                if ($once) {
                    return self::FAILURE;
                }
                sleep($backoff);
                $backoff = min(30, $backoff * 2);

                continue;
            }

            $backoff = 2;
            $peer = stream_socket_get_name($fp, true) ?: 'stream';
            $this->info("Connected to {$peer}");
            ActivityLogger::log('calls', 'stream', 'PBX SMDR stream connected ('.$peer.')');
            $this->pump($fp, $flushSecs);
            fclose($fp);
            $this->warn('PBX stream ended');
            ActivityLogger::log('calls', 'stream', 'PBX SMDR stream ended');

            if ($once) {
                return self::SUCCESS;
            }
            sleep(2);
        }
    }

    /** Read lines and import them in time-boxed batches until EOF. */
    private function pump($fp, int $flushSecs): void
    {
        stream_set_blocking($fp, true);
        stream_set_timeout($fp, 1);

        $buf = '';
        $nextFlush = microtime(true) + $flushSecs;
        while (! feof($fp)) {
            $line = fgets($fp, 8192);
            if ($line === false) {
                $meta = stream_get_meta_data($fp);
                if ($meta['timed_out'] || $meta['eof']) {
                    if ($buf !== '' && microtime(true) >= $nextFlush) {
                        $this->flush($buf);
                        $nextFlush = microtime(true) + $flushSecs;
                    }
                    if ($meta['eof']) {
                        break;
                    }

                    continue;
                }
                break;
            }
            $buf .= $line;
            if (strlen($buf) >= 512 * 1024 || microtime(true) >= $nextFlush) {
                $this->flush($buf);
                $nextFlush = microtime(true) + $flushSecs;
            }
        }
        if ($buf !== '') {
            $this->flush($buf);
        }
    }

    private function flush(string &$buf): void
    {
        $text = $buf;
        $buf = '';
        try {
            $sum = CallAccountingService::import($text, 'stream');
        } catch (RuntimeException $e) {
            $this->warn('Import failed: '.$e->getMessage());

            return;
        }
        $this->line(sprintf(
            '[%s] %d received, %d imported, %d posted (%s), %d duplicates, %d errors',
            now()->format('H:i:s'),
            $sum['received'],
            $sum['imported'],
            $sum['posted'],
            money($sum['charge']),
            $sum['duplicates'],
            count($sum['errors']),
        ));
        foreach (array_slice($sum['errors'], 0, 5) as $err) {
            $this->warn('  '.$err);
        }
    }

    private function connect(string $host, int $port)
    {
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);
        if (! $fp) {
            throw new RuntimeException("Cannot reach {$host}:{$port} (".($errstr ?: "error {$errno}").') – retrying…');
        }

        return $fp;
    }

    private function accept(string $bind, int $port)
    {
        $server = @stream_socket_server("tcp://{$bind}:{$port}", $errno, $errstr);
        if (! $server) {
            throw new RuntimeException("Cannot listen on {$bind}:{$port} (".($errstr ?: "error {$errno}").') – retrying…');
        }
        stream_set_timeout($server, 30);
        $this->line('Waiting for the PABX to connect…');
        $fp = @stream_socket_accept($server, 30);
        if (! $fp) {
            fclose($server);
            throw new RuntimeException('No incoming connection within 30s – retrying…');
        }
        fclose($server);   // one stream per session; the loop re-arms

        return $fp;
    }

    private function resolveHotel(): ?Hotel
    {
        $id = $this->option('hotel');
        if ($id) {
            $hotel = Hotel::withoutGlobalScopes()->find((int) $id);
            if (! $hotel) {
                $this->error("Hotel {$id} not found.");

                return null;
            }

            return $hotel;
        }
        $hotels = Hotel::withoutGlobalScopes()->where('is_active', true)->orderBy('id')->get();
        if ($hotels->count() === 1) {
            return $hotels->first();
        }
        $this->error('Choose a property with --hotel=<id>:');
        foreach ($hotels as $h) {
            $this->line("  {$h->id}  {$h->code}  {$h->name}");
        }

        return null;
    }
}
