<?php

namespace ottimis\phplibs;

use Attribute;
use DateTime;
use ottimis\phplibs\schemas\VALIDATOR_FORMAT;
use ottimis\phplibs\schemas\VALIDATOR_TYPE;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Validator
{
    public function __construct(
        public ?bool             $required = true,
        public ?int              $minLength = null,
        public ?int              $maxLength = null,
        public ?string           $pattern = null,
        public ?VALIDATOR_FORMAT $format = null,
        public ?VALIDATOR_TYPE   $type = null,
        public ?array            $enum = null,
        public ?int              $min = null,
        public ?int              $max = null,
        public ?string           $minDate = null,
        public ?string           $maxDate = null,
        public ?string           $multipleOf = null,
    )
    {
    }

    // Validate and return error message
    public function validate($value): array
    {
        if ($this->required && empty($value)) {
            return [
                'success' => false,
                'message' => 'Value is required',
            ];
        }
        if ($this->minLength !== null && strlen($value) < $this->minLength) {
            return [
                'success' => false,
                'message' => 'Value must be at least ' . $this->minLength . ' characters long',
            ];
        }
        if ($this->maxLength !== null && strlen($value) > $this->maxLength) {
            return [
                'success' => false,
                'message' => 'Value must be at most ' . $this->maxLength . ' characters long',
            ];
        }
        if ($this->pattern !== null && !preg_match($this->pattern, $value)) {
            return [
                'success' => false,
                'message' => 'Value does not match the required pattern: ' . $this->pattern,
            ];
        }
        if ($this->format === 'date' && !DateTime::createFromFormat('Y-m-d', $value)) {
            return [
                'success' => false,
                'message' => 'Value is not a valid date',
            ];
        }
        if ($this->type !== null && gettype($value) !== $this->type->value) {
            return [
                'success' => false,
                'message' => 'Value is not of type ' . $this->type->value,
            ];
        }
        if ($this->enum !== null && !in_array($value, $this->enum)) {
            return [
                'success' => false,
                'message' => 'Value is not one of the allowed values: ' . implode(', ', $this->enum),
            ];
        }
        if ($this->min !== null && $value < $this->min) {
            return [
                'success' => false,
                'message' => 'Value must be at least ' . $this->min,
            ];
        }
        if ($this->max !== null && $value > $this->max) {
            return [
                'success' => false,
                'message' => 'Value must be at most ' . $this->max,
            ];
        }
        return [
            'success' => true,
        ];
    }
}