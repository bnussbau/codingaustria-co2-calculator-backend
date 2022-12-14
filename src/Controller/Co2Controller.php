<?php
declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Co2Controller extends AbstractController
{
    const API_URL = 'https://maps.googleapis.com/maps/api/directions/json';
    const DELIVERY_UNBUNDLED_EMISSION_OVERALL = 600;
    const DELIVERY_BUNDLED_EMISSION_OVERALL = 900;
    const DELIVERY_PER_PEDES_EMISSION = 0; // sing: i can walk 500 miles
    const CAR_CO2_EMISSION_PER_KM = 123;
    const CUSTOMER_PICKUP_FACTOR = 2;
    const HUB_ADDRESS = [
        'street' => 'Mühlgasse 93',
        'zip' => '2380',
        'city' => 'Perchtoldsdorf'
    ];

    #[Route('/api/co2', name: 'app_co2')]
    public function index(Request $request): JsonResponse
    {
        $street = $request->query->get('street');
        $zip = $request->query->get('zip');
        $city = $request->query->get('city');
        $merchantId = $request->query->get('merchantId');
        $datePreference = $request->query->get('datePreference');

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
        $orders = $this->getOrdersByMerchant($conn, $request->query->get('merchantId'));

        return $this->json([
            'co2Savings' => array_values($orders)[0] * 600
        ]);
    }

    #[Route('/api/merchant/address', name: 'app_co2_merchant_address')]
    public function co2MerchantAddress(Request $request, Connection $conn): JsonResponse
    {
        $merchantAddress = $this->getMerchantAddress($conn, $request->query->get('merchantId'));

        return $this->json([
            'address' => array_values($merchantAddress)
        ])->setEncodingOptions(JSON_UNESCAPED_UNICODE);
    }

    #[Route('/api/customer/pickup', name: 'app_co2_customer_pickup')]
    public function co2CustomerPickup(Request $request, Connection $conn, HttpClientInterface $client): JsonResponse
    {
        $street = $request->query->get('street');
        $zip = $request->query->get('zip');
        $city = $request->query->get('city');
        $datePreference = $request->query->get('datePreference');

        $merchantId = $request->query->get('merchantId');
        $merchantAddress = $this->getMerchantAddress($conn, $merchantId);
        $waypoints = [
            $this->encodeAddressForGoogleMaps([
                'street' => $street,
                'zip' => $zip,
                'city' => $city,
            ])
        ];
        $distanceKmPickupByFeet = $this->getDistance($client, $this->encodeAddressForGoogleMaps($merchantAddress), $waypoints, 'walking')['distance'];
        $timeKmPickupByFeet = $this->getDistance($client, $this->encodeAddressForGoogleMaps($merchantAddress), $waypoints, 'walking')['duration'];
        $distanceKmPickup = $this->getDistance($client, $this->encodeAddressForGoogleMaps($merchantAddress), $waypoints)['distance'];
        $distanceKmDeliveryFromHub = $this->getDistance($client, $this->encodeAddressForGoogleMaps(self::HUB_ADDRESS), $waypoints)['distance'];

        $emissions = [
            'co2GramsPickup' => $distanceKmPickup * self::CUSTOMER_PICKUP_FACTOR * self::CAR_CO2_EMISSION_PER_KM,
            'co2GramsDeliveryOptimized' => $distanceKmDeliveryFromHub * self::CAR_CO2_EMISSION_PER_KM,
            'co2GramsDeliveryUnbundled' => self::DELIVERY_UNBUNDLED_EMISSION_OVERALL,
            'co2GramsDeliveryBundled' => self::DELIVERY_BUNDLED_EMISSION_OVERALL,
            'co2GramsSavedMerchant' => array_values($this->getOrdersByMerchant($conn, $merchantId))[0] * 600
        ];

        if ($distanceKmPickupByFeet <= 2) {
            $emissions['co2GramsPickupPerPedesDistance'] = self::DELIVERY_PER_PEDES_EMISSION;
            $emissions['co2GramsPickupPerPedesTime'] = $timeKmPickupByFeet;

        }
        return $this->json($emissions);
    }

    private function getDistance(HttpClientInterface $client, string $origin, array $waypoints, string $mode = 'driving'): array
    {
        $params['origin'] = $params['destination'] = $origin;
        $params['mode'] = $mode;
        $params['waypoints'] = sprintf('optimize:true|%s', join('|', $waypoints));

        $defaultParams = ['key' => $this->getParameter('api_key')];
        $options['query'] = array_merge($defaultParams, $params);

        $response = $client->request(
            'GET',
            self::API_URL,
            $options
        );

        if (Response::HTTP_OK !== $response->getStatusCode()) {
            throw new \Exception('Not possible to query directions api');
        }

        $json = json_decode($response->getContent(), true);
        $distanceMeters = $json['routes'][0]['legs'][0]['distance']['value'];
        $distanceKm = $distanceMeters / 1000;

        $duration = $json['routes'][0]['legs'][0]['duration']['text'];

        return ['distance' => $distanceKm, 'duration' => $duration];
    }

    private function getOrdersByMerchant(Connection $conn, string $merchantId): array
    {
        $stmt = $conn->prepare("SELECT count(*) FROM merchant_order where merchant_id = 0x" . $merchantId);
        $result = $stmt->executeQuery();
        $countOrders = $result->fetchAssociative();

        if (!$countOrders) {
            return [];
        }

        return $countOrders;
    }

    private function getMerchantAddress(Connection $conn, float|bool|int|string|null $merchantId): array
    {
        $stmt = $conn->prepare("SELECT street, zip, city FROM merchant where id = 0x" . $merchantId);
        $result = $stmt->executeQuery();
        $merchantAddress = $result->fetchAssociative();

        if (!$merchantAddress) {
            return [];
        }

        return $merchantAddress;
    }

    private function encodeAddressForGoogleMaps(array $merchantAddress): string
    {
        return sprintf('%s, %s %s', $merchantAddress['street'], $merchantAddress['zip'], $merchantAddress['city']);
    }

    private function getGthAddress(HttpClientInterface $client, $street, $zip, $number)
    {
        $options['headers'] = ['cookie' => $this->getParameter('gth_key')];
        $options['query'] = ['z' => $zip, 's' => $street];
        $response = $client->request(
            'GET',
            'https://www.greentohome.at/app/streetAutoComplete',
            $options
        );

        $json = json_decode($response->getContent(), true);
        $numbers = $json['items'][0]['numbers'];

        $numberResult = array_filter($numbers, function ($k, $v) use ($number) {
            return $k[1] == $number;
        }, ARRAY_FILTER_USE_BOTH);

        return current($numberResult)[0];

    }

    #[Route('/api/greentohome/export', name: 'app_gth_export')]
    public function greentohomeExport(HttpClientInterface $client, Request $request, Connection $conn): JsonResponse
    {

        $street = $request->query->get('street');
        $zip = $request->query->get('zip');
        $number = $request->query->get('number');

        $gthAddressId = $this->getGthAddress($client, $street, $zip, $number);

        $body = '{
          "weight": 5,
          "length": 10,
          "width": 20,
          "height": 30,
          "name": "Test",
          "phone": "+43664111111111",
          "email": "test1@greentohome.at",
          "address": {
            "addressType": "knownAddress",
            "zipCode": "' . $zip . '",
            "streetNumber": "' . $gthAddressId . '",
            "streetName": "adf"
          },
          "addressComment": "12",
          "comment": "",
          "monday": true,
          "tuesday": true,
          "wednesday": true,
          "thursday": true,
          "friday": true,
          "saturday": true,
          "sunday": true,
          "timeFrom": "09:00",
          "timeTo": "17:00",
          "fromAddressBook": "",
          "addToAddressBook": false
        }';

        $options['headers'] = ['cookie' => $this->getParameter('gth_key')];
        $options['body'] = $body;
        $response = $client->request(
            'POST',
            'https://www.greentohome.at/app/GTH-CW436/senden',
            $options
        );

        if (Response::HTTP_OK !== $response->getStatusCode()) {
            throw new \Exception('Not possible to submit order');
        }
        return $this->json([
            'message' => "order successfully transmitted"
        ])->setEncodingOptions(JSON_UNESCAPED_UNICODE);
    }

}
