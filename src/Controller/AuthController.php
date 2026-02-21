<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AuthController extends AbstractController
{
    public function __construct(
        private HttpClientInterface $http,
        private string $keycloakUrl,
        private string $keycloakRealm,
    ) {}

    #[Route('/api/auth', methods: ['POST'])]
    public function auth(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);

        if (empty($body['client_id']) || empty($body['client_secret'])) {
            return new JsonResponse(['error' => 'client_id and client_secret required'], 400);
        }

        try {
            $response = $this->http->request('POST',
                "{$this->keycloakUrl}/realms/{$this->keycloakRealm}/protocol/openid-connect/token",
                [
                    'body' => [
                        'grant_type'    => 'client_credentials',
                        'client_id'     => $body['client_id'],
                        'client_secret' => $body['client_secret'],
                    ]
                ]
            );

            return new JsonResponse($response->toArray());

        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Authentication failed'], 401);
        }
    }
}