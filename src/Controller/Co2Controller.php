<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Query\Expr\From;
use Doctrine\ORM\Query\Expr\Select;
use PDO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\Persistence\ManagerRegistry;

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
        //$entityManager = $doctrine->getManager();

        $merchantId = $request->query->get('merchantId');


        $stmt = $conn->prepare("SELECT count(*) FROM merchant_order where merchant_id = 0x".$merchantId);

        $result = $stmt->executeQuery();
        $countOrders = $result->fetchAssociative();

        return $this->json([
            'co2Savings' => array_values($countOrders)[0]*600
        ]);
    }
}
