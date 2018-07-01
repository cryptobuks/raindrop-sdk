<?php

declare(strict_types=1);

namespace Adrenth\Raindrop;

use Adrenth\Raindrop\Exception\RefreshTokenFailed;
use Adrenth\Raindrop\TokenStorage\TokenStorageInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Class ApiBase
 *
 * @package Adrenth\Raindrop
 */
abstract class ApiBase
{
    private const USER_AGENT = 'adrenth.raindrop-sdk/1.0';

    /**
     * Settings
     *
     * @var ApiSettings
     */
    private $settings;

    /**
     * @var TokenStorageInterface
     */
    private $tokenStorage;

    /**
     * HTTP Client
     *
     * @var \GuzzleHttp\Client
     */
    private $httpClient;

    /**
     * @param ApiSettings $settings
     * @param TokenStorageInterface $tokenStorage
     */
    public function __construct(
        ApiSettings $settings,
        TokenStorageInterface $tokenStorage
    ) {
        $this->settings = $settings;
        $this->tokenStorage = $tokenStorage;
        $this->httpClient = new \GuzzleHttp\Client([
            'base_uri' => $this->settings->getEnvironment()->getApiUrl() . '/hydro/v1/',
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => self::USER_AGENT
            ]
        ]);
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array $options
     * @return ResponseInterface
     * @throws GuzzleException
     */
    protected function callHydroApi(string $method, string $uri, array $options = []): ResponseInterface
    {
        if (!isset($options['handler'])) {
            $options['handler'] = $this->getHandlerStack();
        }

        return $this->httpClient->request($method, $uri, $options);
    }

    /**
     * @throws RefreshTokenFailed
     */
    protected function refreshToken(): ApiAccessToken
    {
        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => $this->settings->getEnvironment()->getApiUrl(),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                    'Authorization' => 'Basic ' . base64_encode(
                        $this->settings->getClientId() . ':' . $this->settings->getClientSecret()
                    )
                ]
            ]);

            $response = $client->post('/authorization/v1/oauth/token?grant_type=client_credentials');
        } catch (RequestException $e) {
            throw new RefreshTokenFailed($e->getMessage());
        }

        try {
            $json = $response->getBody()->getContents();

            $data = \GuzzleHttp\json_decode($json, true);
        } catch (RuntimeException | InvalidArgumentException $e) {
            throw new RefreshTokenFailed('Invalid response from server');
        }

        // TODO: Extend the ApiAccessToken class.
        $accessToken = new ApiAccessToken($data['access_token'], time() + $data['expires_in']);

        $this->tokenStorage->setAccessToken($accessToken);

        return $accessToken;
    }

    /**
     * @return HandlerStack
     */
    private function getHandlerStack(): HandlerStack
    {
        $stack = HandlerStack::create();
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) {
            /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
            $token = $this->getAccessToken();

            if ($token) {
                /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
                return $request->withHeader('Authorization', "Bearer {$token->getToken()}");
            }

            return $request;
        }), 'add_oauth2_header');

        return $stack;
    }

    /**
     * @return null|ApiAccessToken
     * @throws RefreshTokenFailed
     */
    private function getAccessToken(): ?ApiAccessToken
    {
        $accessToken = $this->tokenStorage->getAccessToken();

        if ($accessToken && $accessToken->isExpired()) {
            $this->tokenStorage->unsetAccessToken();
            $accessToken = null;
        }

        if ($accessToken === null) {
            $accessToken = $this->refreshToken();
        }

        return $accessToken;
    }
}