<?php

namespace cryodrift\mailserver;

use cryodrift\fw\Context;
use cryodrift\fw\Core;
use cryodrift\mailsend\Mime;
use cryodrift\mailsend\Smtp;

/**
 * Minimal SMTP server (implicit TLS) that relays using src\mailsend\Smtp
 * Intended for local testing only.
 */
class SmtpServer
{
    public function __construct(
      private string $addr,
      private int $port,
      private string $cert,
      private string $key,
      private bool $recv_enabled,
      private string $recv_maildir,
      private string $hostname,
      private Context $ctx,
    ) {
    }

    public function run(): void
    {
        $ctx = stream_context_create([
          'ssl' => [
            'local_cert' => $this->cert,
            'local_pk' => $this->key,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
            'allow_self_signed' => true,
            'verify_peer' => false,
          ],
        ]);
        $server = stream_socket_server(
          "tls://{$this->addr}:{$this->port}",
          $errno,
          $errstr,
          STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
          $ctx
        );
        Core::echo(__METHOD__, 'listen', $this->addr, $this->port);
        if (!$server) {
            fwrite(STDERR, "Listen failed: $errstr\n");
            exit(1);
        }
        while ($conn = @stream_socket_accept($server, -1)) {
            $peer = @stream_socket_get_name($conn, true) ?: 'unknown';
            Core::echo(__METHOD__, 'accepted', $peer);
            $this->handle($conn);
            fclose($conn);
            Core::echo(__METHOD__, 'closed', $peer);
        }
        fclose($server);
    }

    private function handle($conn): void
    {
        $mailFrom = '';
        $rcpts = [];
        $inData = false;
        $dataLines = [];
        $authLoginStage = null; // 'LOGIN_USER' | 'LOGIN_PASS' | 'PLAIN'
        $authTempUser = null;
        $authed = false;
        $this->write($conn, '220 ' . $this->hostname . ' ESMTP');

        while (($line = fgets($conn)) !== false) {
            $raw = $line;
            $line = rtrim($line, "\r\n");
//            Core::echo(__METHOD__, 'recv.raw.len:', strlen($raw));
//            Core::echo(__METHOD__, 'recv.raw', $line, $this->showRaw($raw));

            if ($inData) {
                if ($line === '.') {
                    // end of DATA
                    $inData = false;
                    $rawMessage = $this->joinCrlf($this->undotStuff($dataLines));
                    $this->processMessage($mailFrom, $rcpts, $rawMessage, $conn);
                    // reset transaction state except HELO
                    $mailFrom = '';
                    $rcpts = [];
                    $dataLines = [];
                    continue;
                }
                $dataLines[] = $raw; // keep original with CRLF
                continue;
            }

            // Handle ongoing AUTH LOGIN/PLAIN challenges (no command keyword expected)
            if ($authLoginStage !== null) {
                if ($authLoginStage === 'LOGIN_USER') {
                    $user = base64_decode($line, true);
                    $user = ($user !== false) ? $user : '';
                    // If username is missing/empty, keep asking for it
                    if ($user === '') {
                        $this->write($conn, '334 VXNlcm5hbWU6'); // Username:
                        continue;
                    }
                    $authTempUser = $user;
                    $authLoginStage = 'LOGIN_PASS';
                    $this->write($conn, '334 UGFzc3dvcmQ6'); // "Password:" prompt
                    continue;
                }
                if ($authLoginStage === 'LOGIN_PASS') {
                    $pass = base64_decode($line, true);
                    $pass = ($pass !== false) ? $pass : '';
                    // If password is empty, keep asking for it
                    if ($pass === '') {
                        $this->write($conn, '334 UGFzc3dvcmQ6'); // Password:
                        continue;
                    }
                    // Log full-length credentials (LOGIN)
                    Core::echo(__METHOD__, 'auth.LOGIN.user', (string)$authTempUser);
                    Core::echo(__METHOD__, 'auth.LOGIN.pass', (string)$pass);
                    $this->ctx->request()->setParam('sessionuser', (string)$authTempUser);
                    $this->ctx->request()->setParam('sessionpass', (string)$pass);
                    $authLoginStage = null;
                    $authTempUser = null;
                    $authed = true;
                    $this->write($conn, '235 2.7.0 Authentication successful');
                    continue;
                }
                if ($authLoginStage === 'PLAIN') {
                    $decoded = base64_decode($line, true);
                    $decoded = ($decoded !== false) ? $decoded : '';
                    // PLAIN format: authorization-id\0authentication-id\0password
                    $parts = explode("\0", $decoded, 3);
                    $authcid = $parts[1] ?? '';
                    $passwd = $parts[2] ?? '';
                    // Log full-length credentials (PLAIN, challenge flow)
                    Core::echo(__METHOD__, 'auth.PLAIN.user', (string)$authcid);
                    Core::echo(__METHOD__, 'auth.PLAIN.pass', (string)$passwd);
                    $this->ctx->request()->setParam('sessionuser', (string)$authcid);
                    $this->ctx->request()->setParam('sessionpass', (string)$passwd);
                    $authLoginStage = null;
                    $authed = true;
                    $this->write($conn, '235 Authentication successful');
                    continue;
                }
            }

            $cmd = strtoupper(strtok($line, ' '));
            $args = trim(substr($line, strlen($cmd)));

            switch ($cmd) {
                case 'EHLO':
                case 'HELO':
                    $this->write($conn, "250-{$this->hostname}");
                    $this->write($conn, '250-AUTH LOGIN PLAIN');
                    $this->write($conn, '250-AUTH=LOGIN PLAIN');
                    $this->write($conn, '250 SIZE 35882577');
                    break;
                case 'AUTH':
                    $mech = strtoupper(strtok($args, ' ')) ?: '';
                    $initial = trim(substr($args, strlen($mech)));
                    if ($mech === 'PLAIN') {
                        if ($initial !== '') {
                            $decoded = base64_decode($initial, true) ?: '';
                            $parts = explode("\0", $decoded, 3);
                            $authcid = $parts[1] ?? '';
                            $passwd = $parts[2] ?? '';
                            if ($authcid === '' || $passwd === '') {
                                // If client sent an initial response but without credentials, ask again to trigger prompt
                                $authLoginStage = 'PLAIN';
                                $this->write($conn, '334 ');
                            } else {
                                // Log full-length credentials (PLAIN, initial response)
                                Core::echo(__METHOD__, 'auth.PLAIN.user', (string)$authcid);
                                Core::echo(__METHOD__, 'auth.PLAIN.pass', (string)$passwd);
                                $this->ctx->request()->setParam('sessionuser', (string)$authcid);
                                $this->ctx->request()->setParam('sessionpass', (string)$passwd);
                                $authed = true;
                                $this->write($conn, '235 2.7.0 Authentication successful');
                            }
                        } else {
                            $authLoginStage = 'PLAIN';
                            $this->write($conn, '334 '); // empty challenge for PLAIN
                        }
                    } elseif ($mech === 'LOGIN') {
                        if ($initial !== '') {
                            $user = base64_decode($initial, true);
                            $authTempUser = ($user !== false) ? $user : '';
                            $authLoginStage = 'LOGIN_PASS';
                            $this->write($conn, '334 UGFzc3dvcmQ6'); // Password:
                        } else {
                            $authLoginStage = 'LOGIN_USER';
                            $this->write($conn, '334 VXNlcm5hbWU6'); // Username:
                        }
                    } else {
                        $this->write($conn, '504 Unrecognized authentication type');
                    }
                    break;

                case 'MAIL':
                    if (!$authed) {
                        Core::echo(__METHOD__, 'reject.MAIL.unauth');
                        $this->write($conn, '530 5.7.0 Authentication required');
                        break;
                    }
                    if (preg_match('/FROM:\s*<([^>]+)>/i', $args, $m)) {
                        $mailFrom = $m[1];
                        Core::echo(__METHOD__, 'MAIL FROM', $mailFrom);
                        $this->write($conn, '250 OK');
                    } else {
                        $this->write($conn, '501 Syntax: MAIL FROM:<address>');
                    }
                    break;

                case 'RCPT':
                    if (preg_match('/TO:\s*<([^>]+)>/i', $args, $m)) {
                        $rcpts[] = $m[1];
                        Core::echo(__METHOD__, 'RCPT TO', $m[1]);
                        $this->write($conn, '250 OK');
                    } else {
                        $this->write($conn, '501 Syntax: RCPT TO:<address>');
                    }
                    break;

                case 'DATA':
                    if (!$authed) {
                        Core::echo(__METHOD__, 'reject.DATA.unauth');
                        $this->write($conn, '530 5.7.0 Authentication required');
                        break;
                    }
                    if (!$mailFrom || empty($rcpts)) {
                        $this->write($conn, '503 Bad sequence of commands');
                        break;
                    }
                    $inData = true;
                    $dataLines = [];
                    $this->write($conn, '354 End data with <CR><LF>.<CR><LF>');
                    break;

                case 'NOOP':
                    $this->write($conn, '250 OK');
                    break;

                case 'RSET':
                    $mailFrom = '';
                    $rcpts = [];
                    $dataLines = [];
                    $inData = false;
                    $this->write($conn, '250 OK');
                    break;

                case 'QUIT':
                    $this->write($conn, '221 Bye');
                    return;

                default:
                    $this->write($conn, '502 Command not implemented');
                    break;
            }
        }
    }

    private function processMessage(string $from, array $rcpts, string $rawMessage, $conn): void
    {
        $this->write($conn, '250 OK queued');
        Core::fileWrite($this->recv_maildir . md5($rawMessage), $rawMessage, 0, true);
        try {
            if ($this->recv_enabled) {
                [$headers, $body] = $this->splitHeadersBody($rawMessage);
                $mime = new Mime($headers);
                $mime->setContent($body);
                Core::echo(__METHOD__, 'from', $from);

                $smtp = Core::newObject(Smtp::class, $this->ctx);
                $smtp->connect($from);
                foreach ($rcpts as $to) {
                    Core::echo(__METHOD__,'from:',$from, 'to:', $to);
                    $smtp->send($from, $to, $mime);
                }
                $smtp->disconnect();
            }
        } catch (\Throwable $e) {
            Core::echo(__METHOD__, 'process.error', $e);
            $this->write($conn, '451 Requested action aborted: local error in processing');
        }
    }

    private function undotStuff(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            // remove one leading dot if present according to SMTP dot-stuffing
            if (isset($l[0]) && $l[0] === '.' && (isset($l[1]) ? $l[1] !== "\n" : true)) {
                $out[] = substr($l, 1);
            } else {
                $out[] = $l;
            }
        }
        return $out;
    }

    private function joinCrlf(array $lines): string
    {
        // $lines already contain original CRLF from fgets; join as-is
        return implode('', $lines);
    }

    private function genMaildirName(string $rcpt): string
    {
        $ts = microtime(true);
        $sec = sprintf('%.0f', floor($ts));
        $usec = sprintf('%06d', (int)(($ts - floor($ts)) * 1_000_000));
        $host = $this->hostname;
        $pid = getmypid();
        $safeRcpt = preg_replace('/[^A-Za-z0-9_.@-]+/', '_', $rcpt);
        $rand = bin2hex(random_bytes(4));
        return $sec . '.' . $usec . '_' . $pid . '_' . $rand . '.' . $host . '_' . $safeRcpt;
    }

    private function splitHeadersBody(string $content): array
    {
        $parts = preg_split("/\r?\n\r?\n/", $content, 2);
        $headersRaw = $parts[0] ?? '';
        $body = $parts[1] ?? '';
        $headers = [];
        if ($headersRaw !== '') {
            // unfold and keep as-is
            $lines = preg_split("/\r?\n/", $headersRaw);
            $current = '';
            foreach ($lines as $l) {
                if ($l !== '' && ($l[0] === ' ' || $l[0] === "\t")) {
                    $current .= $l; // folded continuation
                } else {
                    if ($current !== '') {
                        $headers[] = $current;
                    }
                    $current = $l;
                }
            }
            if ($current !== '') {
                $headers[] = $current;
            }
        }
        return [$headers, $body];
    }

    private function write($conn, string $line): void
    {
        Core::echo(__METHOD__, 'send', $line);
        fwrite($conn, $line . "\r\n");
    }

    private function showRaw(string $s): string
    {
        $out = '';
        for ($i = 0; $i < strlen($s); $i++) {
            $c = $s[$i];
            $hex = strtoupper(bin2hex($c));
            $out .= ($hex === '0D') ? '<CR>' : (($hex === '0A') ? '<LF>' : $c);
        }
        return $out;
    }

}
