<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\CategoryList;
use App\Models\ProductList;
use App\Models\PriceHistory;
use App\Models\DiscountList;
use App\Models\PriceChange;

class getProduct extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:get-product';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */

    protected $date;
    protected $onlyDate;
    protected $productList  = [];
    protected $priceHistory = [];
    protected $productDupCheck = [];
    protected $historyDupCheck = [];

    public function handle()
    {
        $this->date = date("Y-m-d H:i:s");
        $this->onlyDate = date("Y-m-d");

        //costco 상품 SET
        $this->productList  = ProductList::where('shotype', 1)->get()->keyBy('code')->toArray();

        //상품 가격 히스토리 SET
        $this->priceHistory = PriceHistory::where('active_date', $this->onlyDate)->get()->keyBy('code')->toArray();

        $getCategoryList = CategoryList::where('shotype', 1)->get();
        if($getCategoryList)
        {
            foreach($getCategoryList as $key)
            {
                $page = 0;
                while(true)
                {
                    if($this->productProc($key->cat_id, $page) == 0) // 0 => 다음 페이지 없음, 1 => 다음 페이지 있음
                        break;

                    $page++;
                }
            }

        }

    }

    protected function getCurl($cat, $page=0)
    {
        sleep(rand(1, 3));

        // cURL 세션 초기화
        $curl = curl_init();

        // 요청에 필요한 URL
        $url = "???$cat&Page=$page";

        // 요청에 필요한 헤더 설정
        $headers = [
        ];

        // cURL 옵션 설정
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "", // 자동으로 압축 해제
            CURLOPT_HTTPHEADER => $headers,
        ]);

        // 요청 실행 및 응답 저장
        $response = curl_exec($curl);
        curl_close($curl);
        return $response;
    }

    protected function productProc($cat_id, $page=0)
    {
        // Transaction Start
        DB::beginTransaction();

        try
        {
            // CURL REQUEST ERROR HANDLE
            $response = $this->getCurl($cat_id, $page);
            if ($response === false)
            {
                DB::rollBack();
                Log::error("Failed to fetch data for category: $cat_id, page: $page");
                return 0;
            }

            // JSON DECODE ERROR HANDLE
            $decode_res = json_decode($response, true);
            if ($decode_res === null && json_last_error() !== JSON_ERROR_NONE)
            {
                DB::rollBack();
                Log::error('JSON decode error: ' . json_last_error_msg());
                return 0;
            }

            $total_pages = $decode_res['paging']['total'] - 1;

            $productQuery = [];
            $historyQuery = [];
            $changeQuery = [];
            if (is_array($decode_res['']))
            {
                foreach ($decode_res[''] as $key => $val)
                {
                    if (!isset($val['price']))
                        continue;

                    $prtArr = array();
                    $prtArr['code']   = $val['code'];
                    $prtArr['name']   = $val['name'];
                    $prtArr['price']  = $val['price']['value'];
                    $prtArr['img']    = $val['images'][1]['url'];

                    // Discount Set
                    $discount = array();
                    $discountId = null;
                    $discountType = 1; // 할인 타입(1: 미할인, 2: 할인)
                    $changeType = 2; // 가격 변동 타입(1: 할인, 2: 가격 변화)
                    if (!empty($val['ds']['dsv']))
                    {
                        $discountStartDate = Carbon::parse($val['ds']['StartDate']);
                        $discountEndDate = Carbon::parse($val['ds']['EndDate']);
                        $discount['start_date'] = $discountStartDate->format('Y-m-d H:i:s');
                        $discount['end_date'] = $discountEndDate->format('Y-m-d H:i:s');
                        $discount['price'] = $val['ds']['dsv'];

                        // Discount Proc
                        $discountId = $this->setDiscount($prtArr['code'], $discount);

                        $discountType = 2;
                        $changeType = 1;
                    }

                    // Product SET
                    if (isset($this->productList[$prtArr['code']]))
                    {
                        // Product Info Update Prc
                        $this->updateProduct($prtArr, $discountId, $discountType);

                        if ($this->productList[$prtArr['code']]['price'] > $prtArr['price'])
                        {
                            //기존 가격보다 저가로 떨어지면 떨어진 원인을 타입으로 가져가서 가격 변동 TBL에 INSERT
                            $this->setChangeQuery($prtArr, $changeType, $changeQuery);
                        }
                    }
                    else
                    {
                        //Product Live Duplicate Check
                        if(isset($this->productDupCheck[$prtArr['code']]))
                            continue;

                        // Product Insert Query Set
                        $this->setProductQuery($cat_id, $prtArr, $discountId, $discountType, $productQuery);

                        // Product Live Code Push
                        $this->productDupCheck[$prtArr['code']] = true;
                    }

                    // History SET
                    if (!isset($this->priceHistory[$prtArr['code']]))
                    {
                        // History Live Duplicate Check
                        if(isset($this->historyDupCheck[$prtArr['code']]))
                            continue;

                        // History Insert Query Set
                        $this->setHistoryQuery($prtArr, $discountId, $discountType, $historyQuery);

                        // History Live Code Push
                        $this->historyDupCheck[$prtArr['code']] = true;
                    }
                }

                ProductList::insert($productQuery); //상품 정보 INSERT
                PriceHistory::insert($historyQuery); //가격 히스토리 INSERT
                PriceChange::insert($changeQuery); //가격 변동 INSERT

                DB::commit();

                if ($page >= $total_pages)
                    return 0;
                else
                    return 1;
            }

        }
        catch (Exception $e)
        {
            DB::rollBack();

            Log::error('Error fetching products: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());

            return 0; // 해당 상품 리스트 페이지 중단
        }
    }
    
    // 상품 정보 업데이트
    protected function updateProduct($prtArr, $discountId, $discountType)
    {
        ProductList::where('seq', $this->productList[$prtArr['code']]['seq'])
            ->update([
                'price' => $prtArr['price'],
                'ds_seq' => $discountId,
                'ds_type' => $discountType,
                'run_state' => 1,
                'update_date' => $this->date
            ]);
    }
    
    // 가격 변동 쿼리 세팅
    protected function setChangeQuery($prtArr, $changeType, &$changeQuery)
    {
        array_push($changeQuery, [
            'code' => $prtArr['code'],
            'price' => $this->productList[$prtArr['code']]['price'],
            'change_price' => $prtArr['price'],
            'change_type' => $changeType,
            'create_date' => $this->date
        ]);
    }

    //상품 정보 쿼리 세팅
    protected function setProductQuery($cat_id, $prtArr, $discountId, $discountType, &$productQuery)
    {
        array_push($productQuery, [
            'cat_id' => $cat_id,
            'code' => $prtArr['code'],
            'name' => $prtArr['name'],
            'price' => $prtArr['price'],
            'img' => $prtArr['img'],
            'ds_seq' => $discountId,
            'ds_type' => $discountType,
            'shotype' => 1,
            'create_date' => $this->date
        ]);
    }
    
    //상품 가격 쿼리 세팅
    protected function setHistoryQuery($prtArr, $discountId, $discountType, &$historyQuery)
    {
        array_push($historyQuery, [
            'active_date' => $this->onlyDate,
            'code' => $prtArr['code'],
            'price' => $prtArr['price'],
            'ds_seq' => $discountId,
            'ds_type' => $discountType
        ]);
    }

    //할인정보 세팅
    protected function setDiscount($code, $discount)
    {
        $discountId = null;

        $getDiscountList = DiscountList::where('code', $code)
                                        ->where('ds_start_date', $discount['start_date'])
                                        ->where('ds_end_date', $discount['end_date'])
                                        ->first();

        if (!$getDiscountList)
        {
            $insertDiscount = DiscountList::insertGetId([
                'code' => $code,
                'ds_price' => $discount['price'],
                'ds_start_date' => $discount['start_date'],
                'ds_end_date' => $discount['end_date'],
                'first_crawl' => $this->date
            ]);
            $discountId = $insertDiscount;
        }
        else
        {
            DiscountList::where('seq', $getDiscountList->seq)
                ->update([
                    'last_crawl' => $this->date
                ]);
            $discountId = $getDiscountList->seq;
        }

        return $discountId;
    }
    
}
