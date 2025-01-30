<?php

namespace ottimis\phplibs\database_functions;
use ottimis\phplibs\database_functions\schemas\OPERATOR;

class ComplexWhere
{

    protected string|ComplexField $field;
    protected OPERATOR $operator;
    protected string $value;
    protected string $operatorAfter;

    public function __construct(string|ComplexField $field, OPERATOR $operator, string $value, string $operatorAfter = "AND")
    {
        $this->field = $field;
        $this->operator = $operator;
        $this->value = $value;
        $this->operatorAfter = $operatorAfter;
    }
}
