<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use MoySklad\MoySklad; // Добавляем импорт библиотеки
use MoySklad\Entities\Documents\Orders\Product;

class MoySkladController extends Controller
{
    public function getOrders()
    {
        try {
            // Создаем экземпляр клиента
            $sklad = MoySklad::getInstance(
                env('MOYSKLAD_LOGIN'),
                env('MOYSKLAD_PASSWORD')
            );

            // Получаем список заказов (правильный способ для этой библиотеки)
//            $orders = CustomerOrder::query($sklad)
//                ->maxResults(10)
//                ->orderBy('moment', 'desc')
//                ->getList();

            $list = Product::query($sklad, QuerySpecs::create([
                "offset" => 15,
                "maxResults" => 50,
            ]))->getList();

            return view('moysklad.orders', compact('list'));

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
