<?php

namespace ottimis\phplibs\schemas;

enum VALIDATOR_TYPE: string
{
    case STRING = 'string';
    case INTEGER = 'integer';
    case FLOAT = 'float';
    case BOOLEAN = 'boolean';
    case ARRAY = 'array';
    case OBJECT = 'object';
}
