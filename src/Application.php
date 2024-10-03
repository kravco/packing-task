<?php

namespace App;

use Doctrine\ORM\EntityManager;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use ShipMonk\InputMapper\Runtime\Exception\MappingFailedException;

//NOTE: This service will be used in the customer’s shopping cart in order to estimate shipping costs.
// Think of edge cases that can occur in this environment.
//
// Edge cases:
// - Products cannot be packed [into any boxes, or into 1 box]
// - External api has degraded performance [requests are slow]
//   => we should have request timeout set up to avoid slow customer experience

class Application
{

    private EntityManager $entityManager;
    private RequestFactoryInterface $requestFactory;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private ClientInterface $httpClient;
    private CacheInterface $cache;
    private CredentialsInterface $credentials;
    /** @var \ShipMonk\InputMapper\Runtime\Mapper<\App\Input> $itemMapper */
    private \ShipMonk\InputMapper\Runtime\Mapper $itemMapper;

    //FIXME?: I am definitely not happy about how this looks, but i believe that doing it differently would add unnecessary complexity to the task
    public function __construct(EntityManager $entityManager, RequestFactoryInterface $requestFactory, ResponseFactoryInterface $responseFactory, StreamFactoryInterface $streamFactory, ClientInterface $httpClient, CacheInterface $cache, CredentialsInterface $credentials)
    {
        $this->entityManager = $entityManager;
        $this->requestFactory = $requestFactory;
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
        $this->httpClient = $httpClient;
        $this->cache = $cache;
        $this->credentials = $credentials;
        
        $mapperProvider = new \ShipMonk\InputMapper\Runtime\MapperProvider(__DIR__ . '/generated', true);
        $this->itemMapper = $mapperProvider->get(Input::class);
    }

    public function run(RequestInterface $request): ResponseInterface
    {
        //NOTE: [1] Check the request
        //NOTE: for simplicity, we allow all HTTP methods
        //NOTE: for simplicity, we do not care about HTTP headers [Content-Type, Accept, ...]
        //NOTE: for simplicity, we are not logging any relevant events/exceptions
        try {
            $body = $request->getBody()->getContents();
        } catch (\RuntimeException $e) {
            return $this->handleInternalServerError('Unable to read full body contents of the request');
        }

        try {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->handleBadRequest('Unable to parse the request body as JSON');
        }

        try {
            $input = $this->itemMapper->map($json);
        } catch (MappingFailedException $e) {
            return $this->handleBadRequest($e->getMessage());
        }
        $items = $input->products;
        if ($items === []) {
            return $this->handleBadRequest('Input contains no items');
        }

        //NOTE: this may seem too excessive for caching, but since external api has strict rate limits,
        // we want to save all unnecessary requests, i.e. also for different JSON formatting or item rotation/order
        foreach ($items as $item) {
            //NOTE: make sure items dimensions are in increasing order, i.e. width <= height <= length
            self::sortDimensions($item);
        }
        usort($items, function ($a, $b) {
            $result = $a->width <=> $b->width;
            if ($result === 0) {
                $result = $a->height <=> $b->height;
                if ($result === 0) {
                    $result = $a->length <=> $b->length;
                }
            }
            return $result;
        });

        //NOTE: [2] Fetch backend configuration
        try {
            $boxes = $this->entityManager->getRepository('App\Entity\Packaging')->findBy([], ['id' => 'ASC']);
        } catch (\Throwable $e) {
            return $this->handleInternalServerError('Backend configuration not available: ' . $e->getMessage());
        }

        //NOTE: [3] Get the result from the cache, if present
        //NOTE: we expect that the box dimensions do not change after it's been assigned an ID
        $cacheKey = self::computeKey(array_keys($boxes), $items);
        $result = $this->cache->get($cacheKey);
        if ($result === null) {
            //NOTE: [4] Execute external API request: https://www.3dbinpacking.com/en/api-doc#pack-a-shipment
            try {
                $request = $this->requestFactory
                    ->createRequest('POST', 'https://global-api.3dbinpacking.com/packer/packIntoMany')
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($this->streamFactory->createStream(json_encode([
                        'username' => $this->credentials->getUsername(),
                        'api_key' => $this->credentials->getApiKey()->getValue(),
                        'bins' => array_map([__CLASS__, 'convertBoxForApi'], $boxes),
                        'items' => array_map([__CLASS__, 'convertItemForApi'], $items),
                        'params' => [
                            'optimization_mode' => 'bins_number',
                        ],
                    ], JSON_THROW_ON_ERROR)));
                $response = $this->httpClient->sendRequest($request);
                //NOTE: for simplicity, we expect the HTTP client handles redirects, i.e. it does not return 3xx responses
                $code = $response->getStatusCode();
                if ($code < 200 || $code >= 300) {
                    throw new \RuntimeException('Response code not 2xx: ' . $code . ' ' . $response->getReasonPhrase());
                }
                $json = json_decode($response->getBody()->getContents(), null, 512, JSON_THROW_ON_ERROR);
                //FIXME?: I am not sure whether validating the whole schema in production is the right thing to do
                $result = self::validatePackedSchema($json);
                $this->cache->set($cacheKey, $result);
            }
            //NOTE: [5] Fallback to interal computation if external API is inaccessible
            catch (\Throwable $e) {
                $total = new Item(0, 0, 0, 0);
                foreach ($items as $item) {
                    $total->width += $item->width;
                    $total->height = max($item->height, $total->height);
                    $total->length = max($item->length, $total->length);
                    $total->weight += $item->weight;
                    self::sortDimensions($total);
                }
                foreach ($boxes as $box) {
                    $box->sortDimensions();
                }
                usort($boxes, function ($a, $b) {
                    $result = $a->getWidth() <=> $b->getWidth();
                    if ($result === 0) {
                        $result = $a->getHeight() <=> $b->getHeight();
                        if ($result === 0) {
                            $result = $a->getLength() <=> $b->getLength();
                            if ($result === 0) {
                                $result = $a->getMaxWeight() <=> $b->getMaxWeight();
                            }
                        }
                    }
                    return $result;
                });
                //NOTE: find the "smallest" box
                foreach ($boxes as $box) {
                    if ($total->width <= $box->getWidth() && $total->height <= $box->getHeight() && $total->length <= $box->getLength() && $total->weight <= $box->getMaxWeight()) {
                        $result = $box->getId();
                        break;
                    }
                }
            }
        }

        //NOTE: [6] Construct the response
        return $this->withJsonBody($this->responseFactory->createResponse(200), ['box_id' => $result]);
    }

    private function handleInternalServerError(string $message): ResponseInterface {
        //FIXME?: this could also throw an exception that would be handled separately from Application logic
        // e.g. throw new InternalServerErrorException($message, 0, $previous)
        return $this->withJsonBody($this->responseFactory->createResponse(500), compact('message'));
    }

    private function handleBadRequest(string $message): ResponseInterface {
        //FIXME?: this could also throw an exception that would be handled separately from Application logic
        // e.g. throw new BadRequestException($message, 0, $previous)
        return $this->withJsonBody($this->responseFactory->createResponse(400), compact('message'));
    }

    private static function convertBoxForApi(Entity\Packaging $box): object {
        return (object) [
            'id' => $box->getId(),
            'w' => $box->getWidth(),
            'h' => $box->getHeight(),
            //NOTE: 3dbinpacking API uses term "depth", we use term "length"
            'd' => $box->getLength(),
            'max_wg' => $box->getMaxWeight(),
        ];
    }

    private static function convertItemForApi(Item $item): object {
        return (object) [
            'id' => bin2hex(random_bytes(16)),
            'w' => $item->width,
            'h' => $item->height,
            //NOTE: 3dbinpacking API uses term "depth", we use term "length"
            'd' => $item->length,
            'wg' => $item->weight,
            'vr' => 1, // vertical rotation
            'q' => 1, // quantity
        ];
    }

    private static function validatePackedSchema(mixed $json): string | false {
        //NOTE: for simplicity, we are not using any JSON schema library here
        if (!is_object($json) || !property_exists($json, 'bins_packed') || !is_array($json->bins_packed)) {
            throw new \UnexpectedValueException('Packed does not match schema {bins_packed:[]}');
        }
        $bins = $json->bins_packed;
        foreach ($bins as $bin) {
            if (!isset($bin->bin_data) || !is_object($bin->bin_data) || !property_exists($bin->bin_data, 'id') || !is_string($bin->bin_data->id)) {
                throw new \UnexpectedValueException('Response body does not match schema {bins_packed:[{bin_data:{id:string}]}');
            }
        }
        return count($bins) === 1 ? $bins[0]->bin_data->id : false;
    }

    private static function sortDimensions(Item $item): void {
        if ($item->width > $item->height) {
            $tmp = $item->width;
            $item->width = $item->height;
            $item->height = $tmp;
        }
        if ($item->length > $item->height) {
            $tmp = $item->height;
            $item->height = $item->length;
            $item->length = $tmp;
        }
        //NOTE: this repetition is intentional
        if ($item->width > $item->height) {
            $tmp = $item->width;
            $item->width = $item->height;
            $item->height = $tmp;
        }
    }

    /**
     * @param int[] $sortedBoxIds
     * @param Item[] $sortedItems
     * @return string
     */
    private static function computeKey(array $sortedBoxIds, array $sortedItems): string {
        //NOTE: this is modified base64-url encoding [using . instead of -],
        // since sha384 produces 48 bytes, there are no padding characters [=]
        return str_replace(['+', '/'], ['.', '_'], base64_encode(hash('sha384', 'X-Box-Ids: ' . join(',', $sortedBoxIds) . "\n\n" . json_encode($sortedItems), true)));
    }

    private function withJsonBody(ResponseInterface $response, mixed $body): ResponseInterface {
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
    }
}
