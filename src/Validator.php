<?php

namespace ottimis\phplibs;

use Attribute;
use DateTime;
use Exception;
use ottimis\phplibs\Interfaces\OGEnumValidatorInterface;
use ottimis\phplibs\schemas\VALIDATOR_FORMAT;
use ottimis\phplibs\schemas\VALIDATOR_TYPE;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Validator
{
    public function __construct(
        public ?bool             $required = true,
        public mixed             $default = null,
        public ?int              $minLength = null,
        public ?int              $maxLength = null,
        public ?string           $pattern = null,
        public ?VALIDATOR_FORMAT $format = null,
        public ?VALIDATOR_TYPE   $type = null,
        public ?array            $enum = null,
        public ?string           $enumType = null,
        public ?int              $min = null,
        public ?int              $max = null,
        public ?string           $minDate = null,
        public ?string           $maxDate = null,
        public ?string           $multipleOf = null,
        public ?bool             $readOnly = false,
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
        if (empty($value) && !empty($this->default)) {
            $value = $this->default;
        }
        if (empty($value)) {
            return [
                'success' => true,
                'value' => $value,
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
        if ($this->format === VALIDATOR_FORMAT::DATE && !DateTime::createFromFormat('Y-m-d', $value)) {
            return [
                'success' => false,
                'message' => 'Value is not a valid date',
            ];
        }
        if ($this->format === VALIDATOR_FORMAT::DATE_TIME && !DateTime::createFromFormat('Y-m-d H:i:s', $value)) {
            return [
                'success' => false,
                'message' => 'Value is not a valid datetime',
            ];
        }
        if ($this->type !== null)   {
            settype($value, $this->type->value);
        }
        if ($this->type !== null && gettype($value) !== $this->type->value) {
            return [
                'success' => false,
                'message' => 'Value is not of type ' . $this->type->value,
            ];
        }
        if (!empty($this->enum) && !empty($this->enumType)) {
            return [
                'success' => false,
                'message' => 'Cannot have both enum and enumType set',
            ];
        }
        if ($this->enum !== null && !in_array($value, $this->enum, true)) {
            return [
                'success' => false,
                'message' => 'Value is not one of the allowed values: ' . implode(', ', $this->enum),
            ];
        }
        if ($this->enumType !== null && is_subclass_of($this->enumType, OGEnumValidatorInterface::class)) {
            try {
                $value = $this->enumType::fromName($value);
            } catch (Exception) {
                return [
                    'success' => false,
                    'message' => 'Value is not one of the allowed values: ' . implode(', ', $this->enumType::getNames()),
                ];
            }
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
            'value' => $value,
        ];
    }
}
