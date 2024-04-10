<?php

namespace App\Command;

use Exception;
use Carbon\Carbon;
use Pimcore\Db;
use RuntimeException;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;
use Pimcore\Model\Element\Service;
use App\Event\GenerateThumbnailsEvent;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Pimcore\Model\Element\DuplicateFullPathException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Example of usage:
 * php bin/console import:json-url --url="https://liv-cdn.pages.dev/pim/test.json"
 */
class ImportJsonUrl extends Command
{
    private const URL_OPTION = 'url';

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct();
    }


    protected function configure(): void
    {
        $this
            ->setName('import:json-url')
            ->setDescription('Import Product objects from the url with JSON resource')
            ->addOption(static::URL_OPTION, null,InputOption::VALUE_REQUIRED, 'The URL to import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        DataObject::setHideUnpublished(false);

        $url = $input->getOption(static::URL_OPTION);

        if (empty($url)) {
            throw new InvalidArgumentException('The "--url=<URL>" option is required.');
        }

        $content = file_get_contents($url);

        if ($content === false) {
            throw new RuntimeException("Failed to fetch content from $url");
        }

        $products = json_decode($content, true);

        if ($products === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON received from $url");
        }

        foreach ($products['products'] as $productData) {
            $gtin = $productData['gtin'];
            $name = $productData['name'];
            $date = $productData['date'];
            $imageUrl = $productData['image'];

            if (!$gtin) {
                throw new RuntimeException("GTIN is not valid string: $gtin");
            }

            $product = $this->getPimProduct($gtin);

            $product->setName($name);
            $product->setDate(Carbon::parse($date));

            $imageContent = file_get_contents($imageUrl);

            if ($imageContent !== false) {
                try {
                    $asset = $this->uploadImageToAssets($imageContent);
                    $product->setImage($asset);
                } catch (DuplicateFullPathException $exception) {
                    $output->writeln("GTIN: $gtin - Error during asset save: {$exception->getMessage()}");
                }
            } else {
                $output->writeln("GTIN: $gtin - Broken image URL: $imageUrl, skipping asset");
            }

            try {
                $product->save();
            } catch (Exception $exception) {
                $output->writeln("GTIN: $gtin - Product was not save: {$exception->getMessage()}" );
            }
        }

        return Command::SUCCESS;
    }

    protected function getPimProduct(string $gtin): DataObject\Product
    {
        $existingProduct = DataObject\Product::getByGtin($gtin, 1);

        if ($existingProduct instanceof DataObject\Product) {
            return $existingProduct;
        }

        $newProduct = new DataObject\Product();
        $newProduct->setKey(Service::getValidKey($gtin, 'object'));
        $newProduct->setParentId(1);
        $newProduct->setGtin($gtin);

        return $newProduct;
    }

    /**
     * @throws DuplicateFullPathException
     */
    protected function uploadImageToAssets(string $imageContent): Asset\Image
    {
        $checksum = $this->getFileChecksum($imageContent);
        $existingAsset = $this->findAssetByChecksum($checksum);

        if ($existingAsset instanceof Asset\Image) {
            return $existingAsset;
        }

        $asset = new Asset\Image();
        $asset->setFilename(Uuid::v4());
        $asset->setData($imageContent);
        $asset->setParent(Asset::getByPath("/"));
        $asset->save();

        $event = new GenerateThumbnailsEvent($asset);
        $this->eventDispatcher->dispatch($event, GenerateThumbnailsEvent::NAME);

        return $asset;
    }

    protected function getFileChecksum(string $content): string
    {
        return hash('sha3-512', $content);
    }

    protected function findAssetByChecksum(string $checksum): ?Asset
    {
        $existingFiles = $this->getDuplicateAssetsForHash($checksum);

        if ($existingFiles) {
            return Asset::getById($existingFiles[0]);
        }

        return null;
    }

    private function getDuplicateAssetsForHash(string $hash): array
    {
        $query = Db::get()->createQueryBuilder()
            ->select("versions.cid")
            ->from("versions")
            ->innerJoin("versions", "({$this->buildMostRecenVersionSubquery()})", "maxVersion",
                "versions.cid = maxVersion.cid AND versions.versionCount = maxVersion.version")
            ->where("binaryFileHash = ?")
            ->orderBy("versions.cid")
            ->setParameter(0, $hash);

        $result = $query
            ->execute()
            ->fetchFirstColumn();

        return $result;
    }

    private function buildMostRecenVersionSubquery(): ?string
    {
        $sql = Db::get()->createQueryBuilder()
            ->select("cid", "MAX(versionCount) as version")
            ->from("versions")
            ->where("ctype = 'asset'")
            ->groupBy("cid")
            ->getSQL();

        return $sql;
    }
}
