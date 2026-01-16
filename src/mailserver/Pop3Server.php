<?php

namespace cryodrift\mailserver;

use cryodrift\fw\Core;

/**
 * Minimal POP3 server (implicit TLS) for account setup tests
 */
class Pop3Server
{
    public function __construct(
      private string $addr,
      private int $port,
      private string $cert,
      private string $key,
      private string $maildir
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
        // Load mailbox from configured maildir (user-independent)
        $currentUser = null;
        $currentPass = null;
        $mailbox = $this->loadMailbox(); // array of ['path','size','uid','name']
        $deleted = []; // set of indexes (1-based) marked for deletion in this session
        // Send POP3 greeting immediately on connection
        $this->write($conn, '+OK PHP Dummy POP3 Ready');
        while (($line = fgets($conn)) !== false) {
            $raw = $line;
            $line = rtrim($line, "\r\n");
            Core::echo(__METHOD__, 'recv.raw.len:', strlen($raw));
            Core::echo(__METHOD__, 'recv.raw', $line, $this->showRaw($raw));
            if (!preg_match('/^(\S+)(?:\s+(.*))?$/', $line, $m)) {
                $this->write($conn, '-ERR invalid');
                continue;
            }
            $cmd = strtoupper($m[1]);
            $args = $m[2] ?? '';
            Core::echo(__METHOD__, 'cmd', $cmd, $args);


            switch ($cmd) {
                case 'CAPA':
                    // No STLS because we're already in TLS
                    $caps = [
                      '+OK Capability list follows',
                      'USER',
                      'TOP',
                      'UIDL',
                      'PIPELINING',
                      'RESP-CODES',
                      '.',
                    ];
                    foreach ($caps as $c) {
                        $this->write($conn, $c);
                    }
                    break;

                case 'NOOP':
                    $this->write($conn, '+OK');
                    break;

                case 'USER':
                    $user = $args;
                    $currentUser = $user;
                    Core::echo(__METHOD__, 'user', $user);
                    $this->write($conn, '+OK');
                    break;

                case 'PASS':
                    $pass = $args;
                    $currentPass = $pass;
                    Core::echo(__METHOD__, 'pass', $this->redact($pass));
                    $this->write($conn, '+OK');
                    break;

                case 'STAT':
                    $count = 0;
                    $octets = 0;
                    foreach ($mailbox as $i => $msg) {
                        $n = $i + 1;
                        if (isset($deleted[$n])) {
                            continue;
                        }
                        $count++;
                        $octets += $msg['size'];
                    }
                    $this->write($conn, "+OK $count $octets");
                    break;

                case 'LIST':
                    $arg = trim($args);
                    if ($arg !== '') {
                        $n = (int)$arg;
                        if ($n < 1 || $n > count($mailbox) || isset($deleted[$n])) {
                            $this->write($conn, '-ERR no such message');
                        } else {
                            $size = $mailbox[$n - 1]['size'];
                            $this->write($conn, "+OK $n $size");
                        }
                        break;
                    }
                    // Multi-line
                    $visible = [];
                    foreach ($mailbox as $i => $msg) {
                        $n = $i + 1;
                        if (isset($deleted[$n])) {
                            continue;
                        }
                        $visible[] = $n . ' ' . $msg['size'];
                    }
                    $this->write($conn, "+OK " . count($visible) . " messages");
                    foreach ($visible as $ln) {
                        $this->write($conn, $ln);
                    }
                    $this->write($conn, '.');
                    break;

                case 'UIDL':
                    $arg = trim($args);
                    if ($arg !== '') {
                        $n = (int)$arg;
                        if ($n < 1 || $n > count($mailbox) || isset($deleted[$n])) {
                            $this->write($conn, '-ERR no such message');
                        } else {
                            $uid = $mailbox[$n - 1]['uid'];
                            $this->write($conn, "+OK $n $uid");
                        }
                        break;
                    }
                    $this->write($conn, '+OK');
                    foreach ($mailbox as $i => $msg) {
                        $n = $i + 1;
                        if (isset($deleted[$n])) {
                            continue;
                        }
                        $this->write($conn, $n . ' ' . $msg['uid']);
                    }
                    $this->write($conn, '.');
                    break;

                case 'RETR':
                    $n = (int)trim($args);
                    if ($n < 1 || $n > count($mailbox) || isset($deleted[$n])) {
                        $this->write($conn, '-ERR no such message');
                        break;
                    }
                    $content = $this->getMessageContent($mailbox[$n - 1]['path']);
                    $this->write($conn, '+OK ' . strlen($content) . ' octets');
                    $this->sendMultiline($conn, $content);
                    break;

                case 'TOP':
                    $parts = preg_split('/\s+/', trim($args));
                    $n = (int)($parts[0] ?? 0);
                    $lines = (int)($parts[1] ?? 0);
                    if ($n < 1 || $n > count($mailbox) || isset($deleted[$n])) {
                        $this->write($conn, '-ERR no such message');
                        break;
                    }
                    $content = $this->getMessageContent($mailbox[$n - 1]['path']);
                    [$hdr, $body] = $this->splitHeadersBody($content);
                    $bodyLines = $lines > 0 ? implode("\r\n", array_slice(explode("\r\n", $body), 0, $lines)) : '';
                    $payload = $hdr . "\r\n\r\n" . $bodyLines;
                    $this->write($conn, '+OK');
                    $this->sendMultiline($conn, $payload);
                    break;

                case 'DELE':
                    // Deletion disabled by policy
                    $this->write($conn, '-ERR delete disabled');
                    break;

                case 'RSET':
                    $deleted = [];
                    $this->write($conn, '+OK');
                    break;

                case 'QUIT':
                    // deletion disabled: never remove messages
                    $this->write($conn, '+OK bye');
                    Core::echo(__METHOD__, 'logout', 'deleted', 0);
                    return;

                default:
                    $this->write($conn, '-ERR unknown command');
                    break;
            }
        }
    }

    private function write($conn, string $line): void
    {
        fwrite($conn, $line . "\r\n");
        Core::echo(__METHOD__, 'send', $line);
    }

    private function sendMultiline($conn, string $payload): void
    {
        // Assumes $payload uses CRLF line endings
        $lines = explode("\r\n", rtrim($payload, "\r\n"));
        foreach ($lines as $ln) {
            if (strlen($ln) > 0 && $ln[0] === '.') {
                $ln = '.' . $ln; // dot-stuffing
            }
            $this->write($conn, $ln);
        }
        $this->write($conn, '.');
    }

    private function loadMailbox(): array
    {
        $dir = rtrim($this->maildir, '/\\');

        // Recursively scan exactly one base folder ($maildir) and its subfolders
        // using Core::dirList, then sort messages by file modification time.
        $paths = [];
        $iter = Core::dirList($dir, function (\SplFileInfo $file) {
            // include directories to allow recursion, collect files later
            return $file->isDir() || $file->isFile();
        });
        foreach ($iter as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $paths[] = $file->getPathname();
            }
        }

        // Build indexed list with mtime for sorting
        $files = [];
        foreach ($paths as $full) {
            $files[] = [
              'path' => $full,
              'mtime' => @filemtime($full) ?: 0,
            ];
        }

        // Sort by file date/time (oldest first)
        usort($files, function (array $a, array $b) {
            return $a['mtime'] <=> $b['mtime'];
        });

        // Map to mailbox structure expected by the server
        $box = [];
        foreach ($files as $info) {
            $full = $info['path'];
            $name = basename($full);
            $size = @filesize($full) ?: 0;
            // Use bare filename before ":2," for uid stability
            $uidbase = explode(':2,', $name)[0];
            $box[] = [
              'path' => $full,
              'size' => $size,
              'uid' => $uidbase,
              'name' => $name,
            ];
        }
        return $box;
    }

    private function getMessageContent(string $path): string
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            return "";
        }
        // normalize to CRLF
        $data = str_replace(["\r\n", "\r"], "\n", $data);
        $data = str_replace("\n", "\r\n", $data);
        // ensure ends with CRLF
        $len = strlen($data);
        if ($len < 2 || substr($data, -2) !== "\r\n") {
            $data .= "\r\n";
        }
        return $data;
    }

    private function splitHeadersBody(string $content): array
    {
        $pos = strpos($content, "\r\n\r\n");
        if ($pos === false) {
            return [$content, ''];
        }
        $hdr = substr($content, 0, $pos);
        $body = substr($content, $pos + 4);
        return [$hdr, $body];
    }





    private function showRaw(string $s): string
    {
        $vis = strtr($s, ["\r" => "\\r", "\n" => "\\n", "\t" => "\\t"]);
        if (preg_match('/[^\x20-\x7E]/', $s)) {
            $hex = strtoupper(bin2hex($s));
            return $vis . ' [hex: ' . $hex . ']';
        }
        return $vis;
    }

    private function redact(string $s): string
    {
        if ($s === '') {
            return '';
        }
        $len = strlen($s);
        if ($len <= 2) {
            return str_repeat('*', $len);
        }
        return substr($s, 0, 1) . str_repeat('*', max(0, $len - 2)) . substr($s, -1);
    }
}
