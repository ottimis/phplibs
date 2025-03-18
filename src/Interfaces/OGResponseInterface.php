<?php

namespace ottimis\phplibs\Interfaces;

use \Exception;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

interface OGResponseInterface
{
    public bool $success { get; set; }
    public ?array $data { get; set; }
    public ?string $errorMessage { get; set; }
}
