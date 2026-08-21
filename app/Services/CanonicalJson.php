<?php

namespace App\Services;

class CanonicalJson
{
    public function encode(mixed $value): string
    {
        return json_encode(
            $this->normalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    public function hash(mixed $value): string
    {
        return hash('sha256', $this->encode($value));
    }

    public function normalize(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = method_exists($value, 'toArray') ? $value->toArray() : (array) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->normalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item) => $this->normalize($item), $value);
    }
}
