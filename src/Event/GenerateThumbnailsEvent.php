<?php

namespace App\Event;

use Pimcore\Model\Asset;
use Symfony\Contracts\EventDispatcher\Event;

class GenerateThumbnailsEvent extends Event
{
    public const NAME = 'app.generate_thumbnails';

    private Asset $asset;

    public function __construct(Asset $asset)
    {
        $this->asset = $asset;
    }

    public function getAsset(): Asset
    {
        return $this->asset;
    }
}
