<?php

namespace App\Services;

use App\Http\Requests\CheckOutRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductSize;
use App\Repository\Eloquent\OrderDetailRepository;
use App\Repository\Eloquent\OrderRepository;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;


class CheckOutService
{
    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var OrderDetailRepository
     */
    private $orderDetailRepository;

    /**
     * CheckOutService constructor.
     *
     * @param OrderRepository $orderRepository
     */
    public function __construct(OrderRepository $orderRepository, OrderDetailRepository $orderDetailRepository)
    {
        $this->orderRepository = $orderRepository;
        $this->orderDetailRepository = $orderDetailRepository;
    }

    public function index()
    {
        try
        {
            $city = old('city') ?? Auth::user()->address->city;
            $district = old('district') ?? Auth::user()->address->district;
            $ward = old('ward') ?? Auth::user()->address->ward;
            $apartment_number = old('apartment_number') ?? Auth::user()->address->apartment_number;
            $phoneNumber = old('phone_number') ?? Auth::user()->phone_number;
            $fullName = old('full_name') ?? Auth::user()->name;
            $email = old('email') ?? Auth::user()->email;

            $response = Http::withHeaders([
                'token' => '24d5b95c-7cde-11ed-be76-3233f989b8f3'
            ])->get('https://online-gateway.ghn.vn/shiip/public-api/master-data/province');
            $citys = json_decode($response->body(), true);

            $response = Http::withHeaders([
                'token' => '24d5b95c-7cde-11ed-be76-3233f989b8f3'
            ])->get('https://online-gateway.ghn.vn/shiip/public-api/master-data/district', [
                'province_id' => $city,
            ]);
            $districts = json_decode($response->body(), true);

            $response = Http::withHeaders([
                'token' => '24d5b95c-7cde-11ed-be76-3233f989b8f3'
            ])->get('https://online-gateway.ghn.vn/shiip/public-api/master-data/ward', [
                'district_id' => $district,
            ]);
            $wards = json_decode($response->body(), true);

            $payments = Payment::where('status', Payment::STATUS['active'])-> get();

            return [
                'citys' => $citys['data'],
                'districts' => $districts['data'],
                'wards' => $wards['data'],
                'city' => $city,
                'district' => $district,
                'ward' => $ward,
                'apartment_number' => $apartment_number,
                'phoneNumber' => $phoneNumber,
                'email' => $email,
                'fullName' => $fullName,
                'payments' => $payments,
            ];
        } catch (Exception) {
            return [];
        }

    }

    public function store(CheckOutRequest $request)
    {
        try {
            if ($this->checkProductUpdateAfterAddCard()) {
                return redirect()->route('cart.index')->with('error', 'Sản phẩm bạn mua đã được thay đổi thông tin');
            }
            // lấy phí vận chuyển
            $fee = $this->getTransportFee($request->district, $request->ward)."";
            //tạo dữ liệu đơn hàng
            $dataOrder = [
                'id' => time() . mt_rand(111, 999),
                'payment_id' => $request->payment_method,
                'user_id' => Auth::user()->id,
                'total_money' => \Cart::getTotal() + $fee,
                'order_status' => Order::STATUS_ORDER['wait'],
                'transport_fee' => $fee,
                'note' => null,
                'name' => Session::get('info_order.name'),
                'email' => Session::get('info_order.email'),
                'phone' => Session::get('info_order.phone'),
                'address' => Session::get('info_order.address'),
            ];
            DB::beginTransaction();
            // thêm đơn hàng vào csdl
            $order = $this->orderRepository->create($dataOrder);

            // thêm chi tiết vào đơn hàng mới tạo
            foreach(\Cart::getContent() as $product){
                // data order detail
                $orderDetail = [
                    'order_id' => $order->id,
                    'product_size_id' => $product->id,
                    'unit_price' => $product->price,
                    'quantity' => $product->quantity,
                    'import_price' => $product->attributes->import_price
                ];
                $this->orderDetailRepository->create($orderDetail);
            }
            DB::commit();
            // xóa toàn bộ sản phẩm trong giỏ hàng
            \Cart::clear();

            //chuyển hướng người dùng đến trang lịch sử mua hàng
            return redirect()->route('order_history.index');
        } catch (Exception $e) {
            Log::error($e);
            DB::rollBack();
            // check quantity product
            foreach(\Cart::getContent() as $product){
                $productSize = ProductSize::where('id', $product->id)->first();
                if($productSize->quantity < $product->quantity) {
                    \Cart::update(
                        $product->id,
                        [
                            'quantity' => [
                                'relative' => false,
                                'value' => $productSize->quantity
                            ],
                        ]
                    );
                }
            }
            return redirect()->route('cart.index')->with('error', 'Có lỗi xảy ra vui lòng kiểm tra lại');
        }
    }

    public function paymentMomo(CheckOutRequest $request)
    {
        if ($this->checkProductUpdateAfterAddCard()) {
            return redirect()->route('cart.index')->with('error', 'Sản phẩm bạn mua đã được thay đổi thông tin');
        }
        $orderId = time() . mt_rand(111, 999)."";
        $amount = \Cart::getTotal() + $this->getTransportFee($request->district, $request->ward)."";
        $returnUrl = route('checkout.callback_momo');
        $notifyUrl = route('checkout.callback_momo');
        return $this->payWithMoMo($orderId, $amount, $returnUrl, $notifyUrl);
    }

    public function paymentVNPAY(CheckOutRequest $request)
    {
        if ($this->checkProductUpdateAfterAddCard()) {
            return redirect()->route('cart.index')->with('error', 'Sản phẩm bạn mua đã được thay đổi thông tin');
        }
        $orderId = time() . mt_rand(111, 999)."";
        $amount = \Cart::getTotal() + $this->getTransportFee($request->district, $request->ward)."";
        $returnUrl = route('checkout.callback_momo');
        return $this->handlePaymentWithVNPAY($returnUrl, $amount, $orderId);
    }

    public function getTransportFee($district, $ward)
    {
        //get service id
        $fromDistrict = "2027";
        $shopId = "3577591";
        $response = Http::withHeaders([
            'token' => '24d5b95c-7cde-11ed-be76-3233f989b8f3'
        ])->get('https://online-gateway.ghn.vn/shiip/public-api/v2/shipping-order/available-services', [
            "shop_id" => $shopId,
            "from_district" => $fromDistrict,
            "to_district" => $district,
        ]);
        $serviceId = $response['data'][0]['service_type_id'];

        //data get fee
        $dataGetFee = [
            "service_type_id" => $serviceId,
            "insurance_value" => 500000,
            "coupon" => null,
            "from_district_id" => $fromDistrict,
            "to_district_id" => $district,
            "to_ward_code" => $ward,
            "height" => 15,
            "length" => 15,
            "weight" => 1000,
            "width" => 15
        ];
        $response = Http::withHeaders([
            'token' => '24d5b95c-7cde-11ed-be76-3233f989b8f3'
        ])->get('https://online-gateway.ghn.vn/shiip/public-api/v2/shipping-order/fee', $dataGetFee);

        return $response['data']['total'];
    }

    // sau khi thanh toán thành công thì momo sẽ tự động chuyển đến hàm xử này
    public function callbackMomo(Request $request)
    {
        // try {
            if ($request->resultCode == 0 || $request->vnp_ResponseCode == 00) {
                //data order
                $district = Session::get('info_order.district');
                $ward = Session::get('info_order.ward');
                $dataOrder = [
                    'id' => $request->orderId ?? $request->vnp_TxnRef,
                    'payment_id' => (isset($request->vnp_TxnRef)) ? Payment::METHOD['vnpay'] : Payment::METHOD['momo'],
                    'user_id' => Auth::user()->id,
                    'total_money' => $request->amount ?? $request->vnp_Amount / 100,
                    'order_status' => Order::STATUS_ORDER['wait'],
                    'transport_fee' => $this->getTransportFee($district, $ward),
                    'note' => null,
                    'name' => Session::get('info_order.name'),
                    'email' => Session::get('info_order.email'),
                    'phone' => Session::get('info_order.phone'),
                    'address' => Session::get('info_order.address'),
                ];
                DB::beginTransaction();
                // thêm 1 đơn hàng vào database
                $order = $this->orderRepository->create($dataOrder);

                // thêm chi tiết vào cái đơn hàng mới tạo
                foreach(\Cart::getContent() as $product){
                    // data order detail
                    $orderDetail = [
                        'order_id' => $order->id,
                        'product_size_id' => $product->id,
                        'unit_price' => $product->price,
                        'quantity' => $product->quantity,
                        'import_price' => $product->attributes->import_price
                    ];
                    $this->orderDetailRepository->create($orderDetail);
                }
                DB::commit();
                // xóa sản tất cả sản phẩm trong giỏ hàng
                \Cart::clear();

                //chuyển hướng người dùng đến trang lịch sử mua hàng
                return redirect()->route('order_history.index');
            }

            return redirect()->route('checkout.index');
        // } catch (Exception $e) {
        //     Log::error($e);
        //     DB::rollBack();
        //     // check quantity product
        //     foreach(\Cart::getContent() as $product){
        //         $productSize = ProductSize::where('id', $product->id)->first();
        //         if($productSize->quantity < $product->quantity) {
        //             \Cart::update(
        //                 $product->id,
        //                 [
        //                     'quantity' => [
        //                         'relative' => false,
        //                         'value' => $productSize->quantity
        //                     ],
        //                 ]
        //             );
        //         }
        //     }
        //     return redirect()->route('cart.index')->with('error', 'Có lỗi xảy ra vui lòng kiểm tra lại');
        // }
    }

    public function checkSignature(Request $request)
    {
        $partnerCode = $request->partnerCode;
        $accessKey = $request->accessKey;
        $requestId = $request->requestId."";
        $amount = $request->amount."";
        $orderId = $request->orderId."";
        $orderInfo = $request->orderInfo;
        $orderType = $request->orderType;
        $transId = $request->transId;
        $message = $request->message;
        $localMessage = $request->localMessage;
        $responseTime = $request->responseTime;
        $errorCode = $request->errorCode;
        $payType = $request->payType;
        $extraData = $request->extraData;
        $secretKey = env('MOMO_SECRET_KEY');
        $extraData = "";

        $rawHash = "partnerCode=" . $partnerCode .
            "&accessKey=" . $accessKey .
            "&requestId=" . $requestId .
            "&amount=" . $amount .
            "&orderId=" . $orderId .
            "&orderInfo=" . $orderInfo .
            "&orderType=" . $orderType .
            "&transId=" . $transId.
            "&message=" . $message .
            "&localMessage=" . $localMessage.
            "&responseTime=" . $responseTime.
            "&errorCode=" . $errorCode.
            "&payType=" . $payType.
            "&extraData=" . $extraData;
        $signature = hash_hmac("sha256", $rawHash, $secretKey);

        if (hash_equals($signature, $request->signature)) {
            return true;
        }

        return false;
    }

    public function handlePaymentWithVNPAY($vnp_Returnurl, $vnp_Amount, $vnp_TxnRef)
    {
        $startTime = date("YmdHis");
        $expire = date('YmdHis',strtotime('+15 minutes',strtotime($startTime)));

        $vnp_TmnCode = env('VNPAY_TMN_CODE');
        $vnp_HashSecret = env('VNPAY_SECRET_KEY');
        $vnp_Url = env('VNPAY_END_POINT');
        $vnp_Locale = "vn"; //Ngôn ngữ chuyển hướng thanh toán
        $vnp_BankCode = "VNBANK"; //Mã phương thức thanh toán
        $vnp_IpAddr = $_SERVER['REMOTE_ADDR']; //IP Khách hàng thanh toán

        $inputData = array(
            "vnp_Version" => "2.1.0",
            "vnp_TmnCode" => $vnp_TmnCode,
            "vnp_Amount" => $vnp_Amount* 100,
            "vnp_Command" => "pay",
            "vnp_CreateDate" => date('YmdHis'),
            "vnp_CurrCode" => "VND",
            "vnp_IpAddr" => $vnp_IpAddr,
            "vnp_Locale" => $vnp_Locale,
            "vnp_OrderInfo" => "Thanh toan GD:" . $vnp_TxnRef,
            "vnp_OrderType" => "other",
            "vnp_ReturnUrl" => $vnp_Returnurl,
            "vnp_TxnRef" => $vnp_TxnRef,
            "vnp_ExpireDate"=>$expire
        );

        if (isset($vnp_BankCode) && $vnp_BankCode != "") {
            $inputData['vnp_BankCode'] = $vnp_BankCode;
        }

        ksort($inputData);
        $query = "";
        $i = 0;
        $hashdata = "";
        foreach ($inputData as $key => $value) {
            if ($i == 1) {
                $hashdata .= '&' . urlencode($key) . "=" . urlencode($value);
            } else {
                $hashdata .= urlencode($key) . "=" . urlencode($value);
                $i = 1;
            }
            $query .= urlencode($key) . "=" . urlencode($value) . '&';
        }

        $vnp_Url = $vnp_Url . "?" . $query;
        if (isset($vnp_HashSecret)) {
            $vnpSecureHash =   hash_hmac('sha512', $hashdata, $vnp_HashSecret);//
            $vnp_Url .= 'vnp_SecureHash=' . $vnpSecureHash;
        }
        return redirect($vnp_Url);
    }

    public function payWithMoMo($orderId, $amount, $redirectUrl, $ipnUrl)
    {
        $endPoint = env('MOMO_END_POINT');

        $partnerCode = env('MOMO_PARTNER_CODE');;
        $accessKey = env('MOMO_ACCESS_KEY');
        $serectkey = env('MOMO_SECRET_KEY');
        $orderInfo = "Thanh toán qua MoMo";
        $extraData = "";
        $requestId = time().mt_rand(111, 999)."";
        $requestType = "captureWallet";
        $rawHash = "accessKey=" . $accessKey .
            "&amount=" . $amount .
            "&extraData=" . $extraData .
            "&ipnUrl=" . $ipnUrl .
            "&orderId=" . $orderId .
            "&orderInfo=" . $orderInfo .
            "&partnerCode=" . $partnerCode .
            "&redirectUrl=" . $redirectUrl .
            "&requestId=" . $requestId .
            "&requestType=" . $requestType;
        $signature = hash_hmac("sha256", $rawHash, $serectkey);
        $data = array('partnerCode' => $partnerCode,
        'partnerName' => "Test",
        "storeId" => "MomoTestStore",
        'requestId' => $requestId,
        'amount' => $amount,
        'orderId' => $orderId,
        'orderInfo' => $orderInfo,
        'redirectUrl' => $redirectUrl,
        'ipnUrl' => $ipnUrl,
        'lang' => 'vi',
        'extraData' => $extraData,
        'requestType' => $requestType,
        'signature' => $signature);
        // gửi dữ liệu lên cho momo và nhận kết quả về
        $result = Http::acceptJson([
            'application/json'
        ])->post($endPoint, $data);

        $jsonResult = json_decode($result->body(), true);
        // chuyển hướng người dùng sang trang thanh toán của momo
        return redirect($jsonResult['payUrl']);
    }

    private function checkProductUpdateAfterAddCard() 
    {
        $ids = [];
        foreach(\Cart::getContent() as $product){
            $productNew = DB::table('products')->where('id', $product->attributes->product_id)->first();

            if ($productNew->updated_at != $product->attributes->updated_at) {
                $ids[] = $product->id;
            }
        }

        foreach ($ids as $id) {
            \Cart::remove($id);
        }

        return count($ids) > 0;
    }
    // public function payWithMoMo($orderId, $amount, $returnUrl, $notifyurl)
    // {
    //     //đường dẫn để gửi dữ liệu thanh toán cho momo
    //     $endPoint = env('MOMO_END_POINT');
    //     // dữ liệu do momo cấp
    //     $partnerCode = env('MOMO_PARTNER_CODE');
    //     $accessKey = env('MOMO_ACCESS_KEY');
    //     $secretKey = env('MOMO_SECRET_KEY');
    //     $orderInfo = "Thanh toán qua MoMo";
    //     $bankCode = env('MOMO_BANK_CODE');
    //     $requestId = time().mt_rand(111, 999)."";
    //     $requestType = "captureMoMoWallet";
    //     $extraData = "";

    //     //tạo 1 cái chuỗi bao gồm những thôn tin thanh toán
    //     $rawHash = "partnerCode=" . $partnerCode .
    //         "&accessKey=" . $accessKey .
    //         "&requestId=" . $requestId .
    //         "&amount=" . $amount .
    //         "&orderId=" . $orderId .
    //         "&orderInfo=" . $orderInfo .
    //         "&returnUrl=" . $returnUrl .
    //         "&notifyUrl=" . $notifyurl .
    //         "&extraData=" . $extraData;

    //     // tạo 1 chữ ký mã hóa dữ liệu thanh toán gửi lên momo
    //     $signature = hash_hmac("sha256", $rawHash, $secretKey);

    //     // dữ liệu cần gửi cho momo
    //     $data =  array(
    //         'partnerCode' => $partnerCode,
    //         'accessKey' => $accessKey,
    //         'requestId' => $requestId,
    //         'amount' => $amount,
    //         'orderId' => $orderId,
    //         'orderInfo' => $orderInfo,
    //         'returnUrl' => $returnUrl,
    //         'bankCode' => $bankCode,
    //         'notifyUrl' => $notifyurl,
    //         'extraData' => $extraData,
    //         'requestType' => $requestType,
    //         'signature' => $signature
    //     );
    //     // gửi dữ liệu lên cho momo và nhận kết quả về
    //     $result = Http::acceptJson([
    //         'application/json'
    //     ])->post($endPoint, $data);

    //     $jsonResult = json_decode($result->body(), true);
    //     // chuyển hướng người dùng sang trang thanh toán của momo
    //     return redirect($jsonResult['payUrl']);
    // }
}
?>
