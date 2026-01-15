<?php

//declare(strict_types=1);

/**
 *
 * @env MAILSERVER_POP3_ADDRESS="0.0.0.0"
 * @env MAILSERVER_POP3_PORT="1995"
 * @env MAILSERVER_POP3_TLSCERT="G_ROOTDIR.cryodrift/mailserver/tls.crt"
 * @env MAILSERVER_POP3_TLSKEY="G_ROOTDIR.cryodrift/mailserver/tls.key"
 * @env MAILSERVER_POP3_MAILDIR="G_ROOTDIR.cryodrift/mailserver/maildir/"
 *
 * @env MAILSERVER_SMTP_ADDRESS="0.0.0.0"
 * only this port works with thunderbird, other clients not tested
 * @env MAILSERVER_SMTP_PORT="465"
 * @env MAILSERVER_SMTP_TLSCERT="G_ROOTDIR.cryodrift/mailserver/tls.crt"
 * @env MAILSERVER_SMTP_TLSKEY="G_ROOTDIR.cryodrift/mailserver/tls.key"
 * @env MAILSERVER_SMTP_RECV_ENABLED=false
 * @env MAILSERVER_SMTP_RECV_MAILDIR="G_ROOTDIR.cryodrift/mailserver/maildir/"
 * @env MAILSERVER_HOSTNAME="localhost"
 */

use cryodrift\fw\Core;

if (!isset($ctx)) {
    $ctx = Core::newContext(new \cryodrift\fw\Config());
}

$cfg = $ctx->config();


$cfg[\cryodrift\mailserver\Cache::class] = [
  'cachedir' => Core::env('MAILSERVER_CACHEDIR')
];
$cfg[\cryodrift\mailserver\Pop3Server::class] = [
  'addr' => Core::env('MAILSERVER_POP3_ADDRESS'),
  'port' => Core::env('MAILSERVER_POP3_PORT'),
  'cert' => Core::env('MAILSERVER_POP3_TLSCERT'),
  'key' => Core::env('MAILSERVER_POP3_TLSKEY'),
  'maildir' => Core::env('MAILSERVER_POP3_MAILDIR'),
  'hostname' => Core::env('MAILSERVER_HOSTNAME')
];
$cfg[\cryodrift\mailserver\SmtpServer::class] = [
  'addr' => Core::env('MAILSERVER_SMTP_ADDRESS'),
  'port' => Core::env('MAILSERVER_SMTP_PORT'),
  'cert' => Core::env('MAILSERVER_SMTP_TLSCERT'),
  'key' => Core::env('MAILSERVER_SMTP_TLSKEY'),
  'recv_enabled' => Core::env('MAILSERVER_SMTP_RECV_ENABLED'),
  'recv_maildir' => Core::env('MAILSERVER_SMTP_RECV_MAILDIR'),
  'hostname' => Core::env('MAILSERVER_HOSTNAME'),
];

// CLI route
\cryodrift\fw\Router::addConfigs($ctx, [
  'mailserver/cli' => \cryodrift\mailserver\Cli::class,
], \cryodrift\fw\Router::TYP_CLI);

