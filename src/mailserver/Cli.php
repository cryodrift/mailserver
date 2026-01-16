<?php

//declare(strict_types=1);

namespace cryodrift\mailserver;

use cryodrift\fw\Context;
use cryodrift\fw\interface\Handler;
use cryodrift\fw\trait\CliHandler;

class Cli implements Handler
{
    use CliHandler;

    public function handle(Context $ctx): Context
    {
        return $this->handleCli($ctx);
    }

    /**
     * @cli pop3 start POP3 server
     */
    protected function pop3(Pop3Server $srv, Context $ctx): Context
    {
        $srv->run();
        $ctx->response()->setStatusFinal();
        return $ctx;
    }

    /**
     * @cli smtp start SMTP server
     */
    protected function smtp(SmtpServer $srv, Context $ctx): Context
    {
        $srv->run();
        $ctx->response()->setStatusFinal();
        return $ctx;
    }
}
