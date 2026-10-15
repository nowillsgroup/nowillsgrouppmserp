<?php

namespace App\Services\Interfaces;

use App\Models\InterfaceCommand;

/**
 * v7.36 – first live PABX driver: a template-driven TCP command link.
 *
 * Hospitality PBX command protocols are vendor-specific (proprietary dial-codes,
 * AT-style strings or PMS link ASCII protocols). Rather than hard-coding one
 * vendor, the property configures one command template per PMS command under
 * Interfaces › PABX, e.g.
 *
 *   check-in   →  CMD CHECKIN {extension} {name} {cos}
 *   check-out  →  COS {extension} BARRED
 *   COS        →  COS {extension} {cos}
 *   room move  →  MOVE {from} {to}
 *
 * Placeholders are the command payload keys plus {date}, {time} and {datetime}
 * (business date). The built line is written to tcp://host:port followed by
 * CRLF; the reply is matched against the configured success pattern (regex,
 * case-insensitive). Blank pattern = any reply (or none) counts as success for
 * PBXes that do not answer.
 *
 * The same templates are exercised end-to-end by deploy/dev/pbx-sim.mjs and by
 * docs/PBX-ADAPTER.md's certification checklist before hardware acceptance.
 */
class TcpPbxDriver
{
    /** @return array{0: bool, 1: string} [acknowledged, response text] */
    public static function send(array $cfg, InterfaceCommand $cmd): array
    {
        $tpl = trim((string) ($cfg['pbx_cmd_'.$cmd->command] ?? ''));
        if ($tpl === '') {
            return [false, "No command template configured for “{$cmd->command}” – set it under Interfaces › PABX (TCP command templates)."];
        }

        $vars = [];
        foreach (($cmd->payload ?? []) as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $vars['{'.$k.'}'] = (string) ($v ?? '');
            }
        }
        $vars['{date}'] = bdate()->format('Y-m-d');
        $vars['{time}'] = bdate()->format('H:i');
        $vars['{datetime}'] = bdate()->format('Y-m-d H:i');
        $line = strtr($tpl, $vars);
        if (preg_match_all('/\{[a-z0-9_]+\}/i', $line, $m)) {
            return [false, 'Command template references unknown placeholder(s): '.implode(', ', array_unique($m[0])).'.'];
        }

        $host = trim((string) ($cfg['pbx_tcp_host'] ?? ''));
        $port = (int) ($cfg['pbx_tcp_port'] ?? 0);
        if ($host === '' || $port < 1 || $port > 65535) {
            return [false, 'The TCP driver needs the PABX host and port (Interfaces › PABX).'];
        }
        $timeout = max(1, min(60, (int) ($cfg['pbx_tcp_timeout'] ?? 5)));

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
        if (! $fp) {
            return [false, "PABX not reachable at {$host}:{$port} (".($errstr ?: "error {$errno}").') – check the interface PC / network and retry.'];
        }

        try {
            stream_set_timeout($fp, $timeout);
            fwrite($fp, $line."\r\n");
            $resp = '';
            while (! feof($fp)) {
                $chunk = fread($fp, 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $resp .= $chunk;
                if (str_contains($resp, "\n") || strlen($resp) >= 512) {
                    break;
                }
            }
            $meta = stream_get_meta_data($fp);
            $resp = trim($resp);

            $pattern = trim((string) ($cfg['pbx_tcp_ok'] ?? ''));
            if ($pattern === '') {
                // Silent PBX: a completed write is the acknowledgement.
                return [true, $resp !== '' ? mb_substr($resp, 0, 200) : 'OK (sent — no reply expected)'];
            }
            $ok = @preg_match('~'.$pattern.'~iu', $resp) === 1;
            if (! $ok && $meta['timed_out'] && $resp === '') {
                return [false, 'No reply from the PABX within '.$timeout.'s.'];
            }

            return [$ok, $resp !== '' ? mb_substr($resp, 0, 200) : ($ok ? 'OK (no reply text)' : 'Empty reply – did not match the success pattern')];
        } finally {
            fclose($fp);
        }
    }
}
