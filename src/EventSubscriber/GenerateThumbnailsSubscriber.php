<?php

namespace App\EventSubscriber;

use Pimcore\Model\Asset\Image;
use App\Event\GenerateThumbnailsEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class GenerateThumbnailsSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            'app.generate_thumbnails' => 'onAssetUpload',
        ];
    }

    public function onAssetUpload(GenerateThumbnailsEvent $event): void
    {
        $asset = $event->getAsset();

        if ($asset instanceof Image) {
            $asset->getThumbnail("thumb_200")->getPathReference();
            $asset->getThumbnail("thumb_800")->getPathReference();
        }
    }
}
