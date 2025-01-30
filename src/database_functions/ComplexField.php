<?php

namespace ottimis\phplibs\database_functions;

class ComplexField  {

    protected string $field;
    protected string $sprintfString;

    public function __construct(string $field, string $sprintfString)
    {
        $this->field = $field;
        $this->sprintfString = $sprintfString;
    }

    public function __toString(): string
    {
        return sprintf($this->sprintfString, $this->field);
    }

    public function addTablePrefix(string $table): ComplexField
    {
        return new ComplexField($table . '.' . $this->field, $this->sprintfString);
    }
}
