<?php

namespace App\Controller\Admin;

use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AdminController extends AbstractController
{
    #[Route('/admin', name: 'app_admin_dashboard')]
    public function index(OrderRepository $orders, UserRepository $users): Response
    {
        return $this->render('admin/index.html.twig', [
            'orderCount' => count($orders->findAll()),
            'userCount' => count($users->findAll()),
            'recentOrders' => $orders->findBy([], ['createdAt' => 'DESC'], 20),
        ]);
    }
}
