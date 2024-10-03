<?php

namespace App;

class Item {
    public function __construct(
        public float $width,
        public float $height,
        public float $length,
        public float $weight,
        public ?int $id = null,
    ) {}
}
