<?php

namespace ottimis\phplibs\schemas\OGMail;

class CID {
    public string $name;
    public string $url;
    public string $cid;

    public function __construct(string $name, string $url, string $cid) {
        $this->name = $name;
        $this->url = $url;
        $this->cid = $cid;
    }
}
