<?php

use App\Application;
use App\CredentialsInterface;
use Doctrine\ORM\EntityManager;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/** @var EntityManager $entityManager */
$entityManager = require __DIR__ . '/src/bootstrap.php';
$requestFactory = new Http\Factory\Guzzle\RequestFactory;
$responseFactory = new Http\Factory\Guzzle\ResponseFactory;
$streamFactory = new Http\Factory\Guzzle\StreamFactory;
$httpClient = new \GuzzleHttp\Client;
$simpleCache = new Psr16Cache(new ArrayAdapter);
$credentials = new class implements CredentialsInterface {
    public function getUsername(): string
    {
        $value = getenv('CREDENTIALS_USERNAME');
        return $value === false ? null : $value;
    }

    public function getApiKey(): SensitiveParameterValue
    {
        $value = getenv('CREDENTIALS_API_KEY');
        return new SensitiveParameterValue($value === false ? null : $value);
    }
};

$request = new Request('POST', new Uri('http://localhost/pack'), ['Content-Type' => 'application/json'], $argv[1]);

$application = new Application(
    $entityManager,
    $requestFactory,
    $responseFactory,
    $streamFactory,
    $httpClient,
    $simpleCache,
    $credentials,
);
$response = $application->run($request);

echo "<<< In:\n" . Message::toString($request) . "\n\n";
echo ">>> Out:\n" . Message::toString($response) . "\n\n";
