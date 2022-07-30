<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

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
    public function co2Merchant(Request $request): JsonResponse
    {
        $merchantID = $request->query->get('merchantId');
        $em = $this->container->get('doctrine.orm.entity_manager');
        $qb = $em->createQueryBuilder();
        $qb->add('select', new Expr\Select(array('order_id')))
            ->add('from', new Expr\From('merchant_order', 'merchant_order'));
        $query = $qb->getQuery();
        $result = $query->getResult();
        dd($result);

    }
}
