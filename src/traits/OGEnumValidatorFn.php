<?php

namespace ottimis\phplibs\Traits;

use Exception;

/**
 * Trait to be used only in OGEnumValidatorInterface
 */
trait OGEnumValidatorFn
{
    /**
     * @throws Exception
     */
    public static function fromName(string $name): self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        throw new Exception("Invalid enum name: {$name}");
    }

    public static function getNames(): array
    {
        return array_map(fn(self $case) => $case->name, self::cases());
    }
}
