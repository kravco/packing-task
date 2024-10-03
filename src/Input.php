<?php

namespace App;

class Input {
    public function __construct(
        /** @var Item[] */
        public array $products,
    ) {}
}
