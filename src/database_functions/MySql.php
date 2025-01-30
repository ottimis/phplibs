<?php

namespace ottimis\phplibs\database_functions;

use ottimis\phplibs\database_functions\schemas\OPERATOR;

class MySql
{
    public static function dateFormat(string $field, ?string $format = 'Y-m-d H:i:s', ?string $alias = null): ComplexField
    {
        return new ComplexField($field, "DATE_FORMAT(%s, '$format')".($alias ? " as $alias" : ''));
    }

    // public static function equals(string $field, string $value): ComplexWhere
    // {
    //     return new ComplexWhere($field, OPERATOR::EQUALS, $value);
    // }
}
