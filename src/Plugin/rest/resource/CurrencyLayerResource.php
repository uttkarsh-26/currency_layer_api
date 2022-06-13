<?php

namespace Drupal\currency_layer_api\Plugin\rest\resource;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\rest\Plugin\ResourceBase;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class acts as a middleware for an external currency api.
 *
 * @RestResource(
 *   id = "currency_layer_resource",
 *   label = @Translation("Currency Layer Resource"),
 *   uri_paths = {
 *     "canonical" = "/api/currency"
 *   }
 * )
 */
class CurrencyLayerResource extends ResourceBase {

  /**
   * Cid Prefix for the resource.
   */
  const CID_PREFIX = 'route:/api/currency:';

  /**
   * Api url.
   *
   * @var string
   */
  protected $apiUrl;

  /**
   * Api key.
   *
   * @var string
   */
  protected $apiKey;

  /**
   * Http client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $client;

  /**
   * Cache bin service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheService;

  /**
   * Constructs a CurrencyLayerResource object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \GuzzleHttp\Client $client
   *   Http client instance.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, array $serializer_formats, LoggerInterface $logger, Client $client, CacheBackendInterface $cache_backend) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->client = $client;
    $this->cacheService = $cache_backend;
    $this->apiUrl = Settings::get('currency_api_url');
    $this->apiKey = Settings::get('currency_api_key');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest'),
      $container->get('http_client'),
      $container->get('cache.default'),
    );
  }

  /**
   * The GET request handler.
   *
   * This handles the get request to the api by fetching the data from external
   * api and returning a json response.
   *
   * @return Symfony\Component\HttpFoundation\JsonResponse
   *   The JsonResponse object.
   */
  public function get(Request $request): JsonResponse {
    $data = [];
    $query_params = $request->query->all();
    $cid = $this->getCid($query_params);
    if ($cache = $this->cacheService->get($cid)) {
      return new JsonResponse($cache->data);
    }

    $options = [
      'headers' => ['apikey' => $this->apiKey],
      'query' => $query_params,
    ];
    try {
      $response = $this->client->get($this->apiUrl, $options);
      $data = Json::decode($response->getBody()->getContents());
      // Response returns a success flag, we only want to cache when api returns
      // a proper response based on the flag. We pass on any error messages
      // to the api consumer as is, so that they are presented with helpful
      // error messages.
      if (!empty($data['success']) && $data['success'] === TRUE) {
        $this->cacheService->set($cid, $data, time() + 86400);
      }
    }
    catch (\Exception $e) {
      // If the api itself is not available we show our custom error message.
      $this->logger->log('error', $e->getMessage());
      return new JsonResponse(
        [
          'error' => [
            'message' => 'Currency API is not available',
            'code' => 502,
          ],
        ], 502);
    }
    return new JsonResponse($data);
  }

  /**
   * Generates a sorted cid.
   *
   * This helps us cache results regardless of the order of query parameters
   * that are passed in. For example both are picked from cache:
   * ?source=USD&currencies=GBP,EUR and ?currencies=GBP,EUR&source=USD.
   *
   * @param array $query_params
   *   The query parameters.
   *
   * @return string
   *   Generated cid.
   */
  private function getCid(array $query_params): string {
    ksort($query_params);
    $cid = static::CID_PREFIX;
    foreach ($query_params as $key => $value) {
      $cid .= $this->getCidFragment($key, $value);
    }
    return $cid;
  }

  /**
   * Generates a sorted cid fragment.
   *
   * This helps work with both: ?currencies=EUR,GBP and ?currencies=GBP,EUR and
   * same for other parameters.
   *
   * @param string $key
   *   The query parameter name.
   * @param string $value
   *   The value of the query parameter.
   *
   * @return string
   *   Cid fragment generated.
   */
  private function getCidFragment($key, $value): string {
    $value = explode(',', $value);
    sort($value);
    $sorted_value = implode(',', $value);
    return "$key={$sorted_value}:";
  }

}
