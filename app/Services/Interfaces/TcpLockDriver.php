<?php

namespace App\Services\Interfaces;

use App\Models\InterfaceCommand;

/**
 * v7.58 – live door-lock driver: a template-driven TCP link to a card encoder /
 * lock gateway.
 *
 * Lock systems (Salto, Onity, Dormakaba, Assa Abloy, …) all ship a "PMS link"
 * that varies by vendor, but nearly all of them expose the same shape: an
 * ASCII/CRLF command port where the PMS writes one line per command and reads
 * one line back, and where an encode answers with the UID it actually wrote to
 * the card. Rather than hard-coding a vendor, the property configures one
 * template per PMS command under Interfaces › Door locks, e.g.
 *
 *   issue      →  ENCODE {room} {from} {until} {areas}
 *   duplicate  →  COPY {room} {serial}
 *   cancel     →  CANCEL {serial}
 *   checkout   →  EXPIRE {room}
 *   extend     →  EXTEND {room} {until}
 *   read      →  READ
 *
 * Placeholders are the command payload keys plus {date}, {time} and
 * {datetime} (business date). The built line is written to tcp://host:port
 * followed by CRLF; the reply is matched against the success pattern (regex,
 * case-insensitive; blank = any reply counts) and, for an encode, scanned with
 * `lock_reply_serial` for the UID. Without that pattern an encode falls back to
 * a generated serial, which is why the pattern matters: it is the only proof the
 * card the guest holds is the one the PMS recorded.
 *
 * deploy/dev/lock-sim.mjs is the reference encoder and
 * deploy/dev/audit/lock_interface_check.php certifies the protocol mapping
 * before hardware acceptance.
 */
class TcpLockDriver
{
    /** The commands the template map knows about. */
    public const COMMANDS = ['issue', 'duplicate', 'cancel', 'checkout', 'extend', 'read'];

    /**
     * Build the line for a command. Pure: no sockets, so the mapping can be
     * certified without an encoder on the desk.
     *
     * @return array{0: ?string, 1: ?string} [line, error]
     */
    public static function buildLine(array $cfg, InterfaceCommand $cmd): array
    {
        $tpl = trim((string) ($cfg['lock_cmd_'.$cmd->command] ?? ''));
        if ($tpl === '') {
            return [null, "No command template configured for “{$cmd->command}” – set it under Interfaces › Door locks (TCP command templates)."];
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

        if (preg_match('/[\\x00-\\x1F\\x7F]/', $line) || mb_strlen($line) > 512) {
            return [null, 'The rendered encoder command contains control characters or exceeds 512 characters.'];
        }

        return [$line, null];
    }

    /** Pull the card UID out of an encoder reply ("OK UID=A1B2C3D4"). */
    public static function parseSerial(array $cfg, string $reply): ?string
    {
        $pattern = trim((string) ($cfg['lock_reply_serial'] ?? ''));
        if ($pattern === '' || $reply === '') {
            return null;
        }
        if (@preg_match('~'.$pattern.'~iu', $reply, $m) !== 1) {
            return null;
        }
        $serial = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($m[1] ?? '')));

        return $serial !== '' ? mb_substr($serial, 0, 64) : null;
    }

    /**
     * Did the encoder acknowledge? Blank pattern = any reply (or none) counts,
     * which is right for encoders that beep and say nothing.
     *
     * @return array{0: bool, 1: string}
     */
    public static function acknowledged(array $cfg, string $reply, bool $timedOut): array
    {
        $pattern = trim((string) ($cfg['lock_tcp_ok'] ?? ''));
        if ($pattern === '') {
            return [true, $reply !== '' ? mb_substr($reply, 0, 200) : 'OK (sent — no reply expected)'];
        }
        $ok = @preg_match('~'.$pattern.'~iu', $reply) === 1;
        if (! $ok && $timedOut && $reply === '') {
            return [false, 'No reply from the encoder within the timeout.'];
        }

        return [$ok, $reply !== '' ? mb_substr($reply, 0, 200) : ($ok ? 'OK (no reply text)' : 'Empty reply – did not match the success pattern')];
    }

    /**
     * Send one command over the TCP link.
     *
     * @return array{0: bool, 1: string, 2: ?string} [acknowledged, response, card serial]
     */
    public static function send(array $cfg, InterfaceCommand $cmd): array
    {
        [$line, $error] = self::buildLine($cfg, $cmd);
        if ($line === null) {
            return [false, (string) $error, null];
        }

        $host = trim((string) ($cfg['lock_tcp_host'] ?? ''));
        $port = (int) ($cfg['lock_tcp_port'] ?? 0);
        if ($host === '' || $port < 1 || $port > 65535) {
            return [false, 'The TCP driver needs the encoder host and port (Interfaces › Door locks).', null];
        }
        $timeout = max(1, min(60, (int) ($cfg['lock_tcp_timeout'] ?? 5)));

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
        if (! $fp) {
            return [false, "Encoder not reachable at {$host}:{$port} (".($errstr ?: "error {$errno}").') – check the interface PC / cable and retry.', null];
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
            [$ok, $message] = self::acknowledged($cfg, $resp, (bool) ($meta['timed_out'] ?? false));

            // the UID only means anything when the encoder accepted the command
            $serial = $ok && in_array($cmd->command, ['issue', 'duplicate'], true) ? self::parseSerial($cfg, $resp) : null;

            return [$ok, $message, $serial];
        } finally {
            fclose($fp);
        }
    }
}
