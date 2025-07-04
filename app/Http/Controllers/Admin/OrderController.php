<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * @var OrderService
     */
    private $orderService;

    /**
     * BrandController constructor.
     *
     * @param OrderService $orderService
     */
    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    public function index(Request $request)
    {
        return view('admin.order.index', $this->orderService->index($request));
    }

    public function edit(Order $order)
    {
        if (count($this->orderService->edit($order)) == 0) {
            return redirect()->route('admin.orders_index')->with('error', 'Có lỗi xảy ra vui lòng kiểm tra lại');
        }
        return view('admin.order.edit', $this->orderService->edit($order));
    }

    public function update(Order $order, Request $request)
    {
        return $this->orderService->update($order, $request);
    }

    public function delete(Request $request)
    {
        return $this->orderService->delete($request);
    }
}
