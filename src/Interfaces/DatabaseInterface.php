<?php

namespace ottimis\phplibs\Interfaces;

interface DatabaseInterface
{
    public function query(string $sql): mixed;
    public function fetchassoc(mixed $result = null): array|false|null;
    public function fetcharray(mixed $result = null): false|array|null;
    public function fetchobject(mixed $result = null): object|false|null;
    public function numrows(mixed $result = null): int|string;
    public function affectedRows(): int|string;
    public function insert_id(): int|string;
    public function real_escape_string(string $param): string;
    public function error(): string|array;
    public function startTransaction(): void;
    public function commitTransaction(): void;
    public function rollbackTransaction(): void;
    public function close(): bool;
    public function freeresult(mixed $result = null): void;
    public function getDriver(): string;
}