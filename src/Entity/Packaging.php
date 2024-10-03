<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Represents a box available in the warehouse.
 *
 * Warehouse workers pack a set of products for a given order into one of these boxes.
 */
#[ORM\Entity]
class Packaging
{

    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(type: Types::FLOAT)]
    private float $width;

    #[ORM\Column(type: Types::FLOAT)]
    private float $height;

    #[ORM\Column(type: Types::FLOAT)]
    private float $length;

    #[ORM\Column(type: Types::FLOAT)]
    private float $maxWeight;

    public function __construct(float $width, float $height, float $length, float $maxWeight)
    {
        $this->width = $width;
        $this->height = $height;
        $this->length = $length;
        $this->maxWeight = $maxWeight;
    }

    public function getId(): ?int {
        return $this->id;
    }

    public function getWidth(): float {
        return $this->width;
    }

    public function getHeight(): float {
        return $this->height;
    }

    public function getLength(): float {
        return $this->length;
    }

    public function getMaxWeight(): float {
        return $this->maxWeight;
    }

    public function sortDimensions(): void {
        if ($this->width > $this->height) {
            $tmp = $this->width;
            $this->width = $this->height;
            $this->height = $tmp;
        }
        if ($this->length > $this->height) {
            $tmp = $this->height;
            $this->height = $this->length;
            $this->length = $tmp;
        }
        //NOTE: this repetition is intentional
        if ($this->width > $this->height) {
            $tmp = $this->width;
            $this->width = $this->height;
            $this->height = $tmp;
        }
    }
}
