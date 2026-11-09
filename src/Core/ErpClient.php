<?php
/**
 * @package WCOSR
 */

declare(strict_types=1);

namespace WCOSR\Core;

interface ErpClient
{
    /**
     * Deliver a job to the ERP. Must not throw for HTTP/transport failures;
     * return an ErpResponse with status 0 and an error message instead.
     */
    public function send(Job $job): ErpResponse;
}
