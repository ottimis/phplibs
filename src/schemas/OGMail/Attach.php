<?php

namespace ottimis\phplibs\schemas\OGMail;

class Attach {
    public string $name;
    public string $path;

    public function __construct(string $name, string $path) {
        $this->name = $name;
        $this->path = $path;
    }
}
