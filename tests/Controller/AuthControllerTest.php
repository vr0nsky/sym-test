<?php

namespace App\Tests\Controller;

use App\Tests\Stub\ResettableHttpClientStub;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\HttpClient\ResponseInterface;

class AuthControllerTest extends WebTestCase
{
    public function testMissingCredentialsReturns400(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/auth', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testMissingClientSecretReturns400(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/auth', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['client_id' => 'my-client'])
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public function testKeycloakSuccessReturnsToken(): void
    {
        $client = static::createClient();

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'access_token' => 'test.jwt.token',
            'token_type'   => 'Bearer',
            'expires_in'   => 1800,
        ]);

        $mockHttp = $this->createMock(ResettableHttpClientStub::class);
        $mockHttp->method('request')->willReturn($mockResponse);

        static::getContainer()->set('keycloak.http_client', $mockHttp);

        $client->request('POST', '/api/auth', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['client_id' => 'my-client', 'client_secret' => 'my-secret'])
        );

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('test.jwt.token', $data['access_token']);
        $this->assertEquals('Bearer', $data['token_type']);
    }

    public function testKeycloakFailureReturns401(): void
    {
        $client = static::createClient();

        $mockHttp = $this->createMock(ResettableHttpClientStub::class);
        $mockHttp->method('request')->willThrowException(new \Exception('Keycloak unreachable'));

        static::getContainer()->set('keycloak.http_client', $mockHttp);

        $client->request('POST', '/api/auth', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['client_id' => 'my-client', 'client_secret' => 'my-secret'])
        );

        $this->assertResponseStatusCodeSame(401);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('Authentication failed', $data['error']);
    }
}
