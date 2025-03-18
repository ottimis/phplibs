<?php

namespace ottimis\phplibs\schemas\Base;

use ottimis\phplibs\Interfaces\OGResponseInterface;

class OGResponse implements OGResponseInterface
{
    public bool $success;
    public ?array $data;
    public ?string $errorMessage;

    public function __construct(
        bool    $success,
        ?array  $data = null,
        ?string $errorMessage = null
    )
    {
        $this->success = $success;
        $this->data = $data;
        $this->errorMessage = $errorMessage;
    }
}
