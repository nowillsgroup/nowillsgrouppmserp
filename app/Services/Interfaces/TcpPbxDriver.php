<?php

namespace App\Services\Interfaces;

use App\Models\InterfaceCommand;

/**
 * v7.36 – template-driven TCP command link for vendor-specific hospitality
 * PABX protocols. The property supplies templates rather than the PMS claiming
 * compatibility with a specific PABX without its protocol documentation.
 *
 * Templates support payload keys plus {date}, {time} and {datetime} (business
 * date). The rendered ASCII line is sent with CRLF and a reply is matched
 * against the configured case-insensitive success regex. Blank regex means a
 * successful completed write is accepted, for silent PABX command ports.
 *
 * End-to-end simulator: deploy/dev/pbx-sim.mjs. Certification guide:
 * docs/PBX-ADAPTER.md.
 */
class TcpPbxDriver
{
    /** Build one command line without network access; used by the certification harness. */
    public static function buildLine(array $cfg, InterfaceCommand $cmd): array
    {
        $tpl = trim((string) ($cfg['pbx_cmd_'.$cmd->command] ?? ''));
        if ($tpl === '') {
            return [null, "No command template configured for “{$cmd->command}” – set it under Interfaces › PABX (TCP command templates)."];
        }

        $vars = [];
        foreach (($cmd->payload ?? []) as $k => $v) {
            if (is_array($v)) {
                $vars['{'.$k.'}'] = implode(',', array_map('strval', $v));
            } elseif (is_scalar($v) || $v === null) {
                $vars['{'.$k.'}'] = (string) ($v ?? '');
            }
        }
        $vars['{date}'] = bdate()->format('Y-m-d');
        $vars['{time}'] = bdate()->format('H:i');
        $vars['{datetime}'] = bdate()->format('Y-m-d H:i');
        $line = strtr($tpl, $vars);
        if (preg_match_all('/\{[a-z0-9_]+\}/i', $line, $m)) {
            return [null, 'Command template references unknown placeholder(s): '.implode(', ', array_unique($m[0])).'.'];
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $line) || mb_strlen($line) > 512) {
            return [null, 'The rendered PABX command contains control characters or exceeds 512 characters.'];
        }

        return [$line, null];
    }

    /** Interpret the response in isolation, so acknowledgements can be tested without a socket. */
    public static function acknowledged(array $cfg, string $response, bool $timedOut): array
    {
        $response = trim($response);
        $pattern = trim((string) ($cfg['pbx_tcp_ok'] ?? ''));
        if ($pattern === '') {
            return [true, $response !== '' ? mb_substr($response, 0, 200) : 'OK (sent — no reply expected)'];
        }
        $ok = @preg_match('~'.$pattern.'~iu', $response) === 1;
        if (! $ok && $timedOut && $response === '') {
            return [false, 'No reply from the PABX within the configured timeout.'];
        }

        return [$ok, $response !== '' ? mb_substr($response, 0, 200) : ($ok ? 'OK (no reply text)' : 'Empty reply – did not match the success pattern')];
    }

    /** @return array{0: bool, 1: string} [acknowledged, response text] */
    public static function send(array $cfg, InterfaceCommand $cmd): array
    {
        [$line, $error] = self::buildLine($cfg, $cmd);
        if ($line === null) {
            return [false, (string) $error];
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
            $out = $line."\r\n";
            $written = 0;
            $length = strlen($out);
            while ($written < $length) {
                $n = @fwrite($fp, substr($out, $written));
                if ($n === false || $n === 0) {
                    return [false, 'Could not write the complete command to the PABX TCP socket.'];
                }
                $written += $n;
            }

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

            return self::acknowledged($cfg, $resp, (bool) ($meta['timed_out'] ?? false));
        } finally {
            fclose($fp);
        }
    }
}
