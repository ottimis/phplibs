<?php

namespace ottimis\phplibs\Interfaces;

interface OGEnumValidatorInterface
{
    public static function fromName(string $name): self;
    public static function getNames(): array;
}
