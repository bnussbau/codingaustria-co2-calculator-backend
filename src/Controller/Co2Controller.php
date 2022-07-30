<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use PDO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Co2Controller extends AbstractController
{
    #[Route('/api/co2', name: 'app_co2')]
    public function index(Request $request): JsonResponse
    {
        $street = $request->query->get('street');
        $zip = $request->query->get('zip');
        $city = $request->query->get('city');
        $merchantId = $request->query->get('merchantId');
        $datePreference = $request->query->get('merchantId');

        //dd($request->query);
        return $this->json([
            'street' => $street,
            'zip' => $zip,
            'city' => $city,
            'merchantId' => $merchantId,
            'datePreference' => $datePreference
        ]);
    }

    #[Route('/api/merchant/co2', name: 'app_co2_merchant')]
    public function co2Merchant(Request $request, Connection $conn): JsonResponse
    {
        $merchantId = $request->query->get('merchantId');

        $stmt = $conn->prepare("SELECT count(*) FROM merchant_order where merchant_id = 0x".$merchantId);

        $result = $stmt->executeQuery();
        $countOrders = $result->fetchAssociative();

        return $this->json([
            'co2Savings' => array_values($countOrders)[0]*600
        ]);
    }

    #[Route('/api/merchant/address', name: 'app_co2_merchant_address')]
    public function co2MerchantAddress(Request $request, Connection $conn): JsonResponse
    {
        $merchantId = $request->query->get('merchantId');


        $stmt = $conn->prepare("SELECT street, zip, city FROM merchant where id = 0x".$merchantId);

        $result = $stmt->executeQuery();
        $merchantAddress = $result->fetchAssociative();
        return $this->json([
            'address' => array_values($merchantAddress)
        ])->setEncodingOptions(JSON_UNESCAPED_UNICODE);
    }


    #[Route('/api/test', name: 'test')]
    public function test(Request $request, HttpClientInterface $client): JsonResponse
    {
        $valid = [
            'Karlsplatz, Wien',
            'Südtiroler Pl., 1040 Wien',
            'Erdbergstraße 131, 1030 Wien',
            'Litfaßstraße 13-7, 1030 Wien',
            'Salzburg Hbf, Südtiroler Pl. 1, 5020 Salzburg',
        ];
       return $this->getDistance($client, 'Flughafen Wien', $valid);
    }

    public function getDistance(HttpClientInterface $client, string $origin, array $waypoints): JsonResponse
    {
        $params['origin'] = $origin;
        $params['destination'] = $origin;
        $params['mode'] = 'driving';
        $params['waypoints'] = sprintf('optimize:true|%s', join('|', $waypoints));
        $options = [];

        // Parameters for Auth
        $defaultParams = ['key' => $this->getParameter('api_key')];

        // Query
        $options['query'] = array_merge($defaultParams, $params);

        $response = $client->request(
            'GET',
            'https://maps.googleapis.com/maps/api/directions/json',
            $options
        );

        $json = json_decode($response->getContent(), true);
        $distanceMeters = $json['routes'][0]['legs'][0]['distance']['value'];
        $distanceKm = $distanceMeters / 1000;

        // Error Handler
        if (Response::HTTP_OK !== $response->getStatusCode()) {
            return new JsonResponse(['error' => $response->getContent()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['overall_distance' => $distanceKm]);
    }
}
